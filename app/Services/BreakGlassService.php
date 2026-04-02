<?php

// ─── BreakGlassService ─────────────────────────────────────────────────────────
// Manages HIPAA emergency access override (break-the-glass) events.
//
// HIPAA permits emergency access when medically necessary (45 CFR §164.312(a)(2)(ii)).
// This service implements the audit trail and alert requirements for that exception.
//
// Access rules:
//   - Read-only, participant-scoped (not tenant-wide)
//   - 4-hour TTL (BreakGlassEvent::ACCESS_DURATION_HOURS)
//   - Rate-limited: max 3 requests per user per 24 hours
//   - Every event creates a critical alert for it_admin + qa_compliance
//   - Every event is written to the immutable audit log with severity=critical
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BreakGlassEvent;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BreakGlassService
{
    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    // ── Request Access ─────────────────────────────────────────────────────────

    /**
     * Grant emergency read access to a participant's chart.
     *
     * Creates an audit log entry (severity=critical) and a critical alert
     * for it_admin + qa_compliance requiring supervisor acknowledgment.
     *
     * @throws ValidationException if justification < 20 chars or rate limit exceeded
     */
    public function requestAccess(User $user, Participant $participant, string $justification, ?string $ipAddress = null): BreakGlassEvent
    {
        // Validate justification length — must be clinically meaningful
        if (strlen(trim($justification)) < 20) {
            throw ValidationException::withMessages([
                'justification' => ['Justification must be at least 20 characters.'],
            ]);
        }

        // Rate limit: max 3 requests per user per 24 hours (abuse prevention)
        $recentCount = BreakGlassEvent::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recentCount >= BreakGlassEvent::RATE_LIMIT_PER_DAY) {
            throw ValidationException::withMessages([
                'justification' => ['Emergency access rate limit exceeded. Contact IT Administration.'],
            ]);
        }

        $grantedAt  = now();
        $expiresAt  = $grantedAt->copy()->addHours(BreakGlassEvent::ACCESS_DURATION_HOURS);

        $event = BreakGlassEvent::create([
            'user_id'           => $user->id,
            'tenant_id'         => $user->tenant_id,
            'participant_id'    => $participant->id,
            'justification'     => $justification,
            'access_granted_at' => $grantedAt,
            'access_expires_at' => $expiresAt,
            'ip_address'        => $ipAddress,
        ]);

        // Immutable audit record — severity=critical, records full context
        AuditLog::record(
            action:      'break_glass_access',
            tenantId:    $user->tenant_id,
            userId:      $user->id,
            resourceType:'participant',
            resourceId:  $participant->id,
            description: "Emergency chart access invoked for participant #{$participant->id} ({$participant->first_name} {$participant->last_name}). Justification: {$justification}",
            oldValues:   ['normal_department' => $user->department, 'normal_role' => $user->role],
            newValues:   ['expanded_scope' => 'read_all_participant_modules', 'access_expires_at' => $expiresAt->toIso8601String(), 'justification' => $justification],
        );

        // Critical alert to IT Admin and QA — requires supervisor acknowledgment
        $participantName = $participant->first_name . ' ' . $participant->last_name;
        $userName        = $user->first_name . ' ' . $user->last_name;

        $this->alerts->create([
            'tenant_id'          => $user->tenant_id,
            'participant_id'     => $participant->id,
            'source_module'      => 'break_glass',
            'alert_type'         => 'break_glass_access',
            'title'              => 'Emergency Chart Access Invoked',
            'message'            => "{$userName} ({$user->department}) invoked emergency access for {$participantName}. Review required.",
            'severity'           => 'critical',
            'target_departments' => ['it_admin', 'qa_compliance'],
            'created_by_system'  => true,
            'metadata'           => [
                'break_glass_event_id' => $event->id,
                'user_id'              => $user->id,
                'participant_id'       => $participant->id,
                'expires_at'           => $expiresAt->toIso8601String(),
            ],
        ]);

        return $event;
    }

    // ── Access Check ───────────────────────────────────────────────────────────

    /**
     * Returns true if the user has an active (non-expired) break-glass event
     * for the specified participant.
     */
    public function hasActiveAccess(User $user, Participant $participant): bool
    {
        return BreakGlassEvent::where('user_id', $user->id)
            ->where('participant_id', $participant->id)
            ->active()
            ->exists();
    }

    // ── Revoke Access ──────────────────────────────────────────────────────────

    /**
     * Immediately revoke an active break-glass access by setting expiry to now.
     * Logs the revocation to the immutable audit log.
     */
    public function revokeAccess(BreakGlassEvent $event, User $supervisor): void
    {
        // Append-only model — we update expiry only (not a full record mutation)
        \Illuminate\Support\Facades\DB::table('emr_break_glass_events')
            ->where('id', $event->id)
            ->update(['access_expires_at' => now()]);

        AuditLog::record(
            action:      'break_glass_revoked',
            tenantId:    $supervisor->tenant_id,
            userId:      $supervisor->id,
            resourceType:'break_glass_event',
            resourceId:  $event->id,
            description: "Emergency access revoked by supervisor for event #{$event->id}",
        );
    }

    // ── Acknowledge ────────────────────────────────────────────────────────────

    /**
     * Supervisor acknowledges they have reviewed the break-glass event.
     */
    public function acknowledge(BreakGlassEvent $event, User $supervisor): void
    {
        \Illuminate\Support\Facades\DB::table('emr_break_glass_events')
            ->where('id', $event->id)
            ->update([
                'acknowledged_by_supervisor_user_id' => $supervisor->id,
                'acknowledged_at'                    => now(),
            ]);
    }
}
