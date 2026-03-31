<?php

// ─── DayCenterController ──────────────────────────────────────────────────────
// Manages day center attendance for enrolled PACE participants.
// Access: activities, it_admin, super_admin (other depts can view)
//
// Routes:
//   GET  /scheduling/day-center            — attendance page (Inertia)
//   GET  /scheduling/day-center/roster     — JSON: enrolled participants for a date/site
//   POST /scheduling/day-center/check-in   — mark participant present / check-in time
//   POST /scheduling/day-center/absent     — mark participant absent with reason
//   GET  /scheduling/day-center/summary    — JSON: attendance summary counts for date range
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\DayCenterAttendance;
use App\Models\Participant;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DayCenterController extends Controller
{
    /**
     * GET /scheduling/day-center
     * Inertia page: day center attendance management.
     * Defaults to today's date and the user's site.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $date = $request->query('date', now()->toDateString());
        $siteId = $request->query('site_id', $user->site_id);

        // Load today's attendance records for this site
        $attendance = DayCenterAttendance::forTenant($user->tenant_id)
            ->forDate($date)
            ->forSite($siteId)
            ->with('participant:id,mrn,first_name,last_name,preferred_name')
            ->orderBy('check_in_time')
            ->get();

        // Summary counts for the selected date
        $summary = [
            'total'   => $attendance->count(),
            'present' => $attendance->whereIn('status', ['present', 'late'])->count(),
            'absent'  => $attendance->where('status', 'absent')->count(),
            'excused' => $attendance->where('status', 'excused')->count(),
            'late'    => $attendance->where('status', 'late')->count(),
        ];

        return Inertia::render('Scheduling/DayCenter', [
            'attendance'   => $attendance,
            'summary'      => $summary,
            'selectedDate' => $date,
            'selectedSite' => $siteId,
            'statusLabels' => DayCenterAttendance::STATUS_LABELS,
            'absentReasons'=> DayCenterAttendance::ABSENT_REASONS,
            'canManage'    => in_array($user->department, ['activities', 'it_admin', 'super_admin'])
                              || ($user->role === 'super_admin'),
        ]);
    }

    /**
     * GET /scheduling/day-center/roster
     * JSON: paginated enrolled participants for a given date/site,
     * merged with any existing attendance records for that day.
     */
    public function roster(Request $request): JsonResponse
    {
        $user   = $request->user();
        $date   = $request->query('date', now()->toDateString());
        $siteId = (int) $request->query('site_id', $user->site_id);

        // Enrolled participants at this site
        $participants = Participant::where('tenant_id', $user->tenant_id)
            ->where('site_id', $siteId)
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->select('id', 'mrn', 'first_name', 'last_name', 'preferred_name')
            ->orderBy('last_name')
            ->get();

        // Existing attendance records for the date
        $records = DayCenterAttendance::forTenant($user->tenant_id)
            ->forDate($date)
            ->forSite($siteId)
            ->pluck('status', 'participant_id')
            ->toArray();

        $roster = $participants->map(fn ($p) => [
            'id'             => $p->id,
            'mrn'            => $p->mrn,
            'name'           => "{$p->last_name}, {$p->first_name}",
            'preferred_name' => $p->preferred_name,
            'attendance'     => $records[$p->id] ?? null,
        ]);

        return response()->json(['roster' => $roster]);
    }

    /**
     * POST /scheduling/day-center/check-in
     * Mark participant present (or late). Creates or updates attendance record.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $validated = $request->validate([
            'participant_id' => ['required', 'integer', 'exists:emr_participants,id'],
            'site_id'        => ['required', 'integer', 'exists:shared_sites,id'],
            'attendance_date'=> ['required', 'date'],
            'check_in_time'  => ['nullable', 'date_format:H:i'],
            'status'         => ['required', 'in:present,late'],
        ]);

        $this->verifyParticipantTenant($validated['participant_id'], $user->tenant_id);

        $attendance = DayCenterAttendance::updateOrCreate(
            [
                'tenant_id'       => $user->tenant_id,
                'participant_id'  => $validated['participant_id'],
                'site_id'         => $validated['site_id'],
                'attendance_date' => $validated['attendance_date'],
            ],
            [
                'status'              => $validated['status'],
                'check_in_time'       => $validated['check_in_time'] ?? now()->format('H:i:s'),
                'absent_reason'       => null,
                'recorded_by_user_id' => $user->id,
            ]
        );

        AuditLog::record(
            action: 'day_center.check_in',
            resourceType: 'DayCenterAttendance',
            resourceId: $attendance->id,
            userId: $user->id,
            tenantId: $user->tenant_id,
            newValues: ['status' => $validated['status'], 'date' => $validated['attendance_date']],
        );

        return response()->json(['attendance' => $attendance]);
    }

    /**
     * POST /scheduling/day-center/absent
     * Mark participant absent or excused with a reason.
     */
    public function markAbsent(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $validated = $request->validate([
            'participant_id' => ['required', 'integer', 'exists:emr_participants,id'],
            'site_id'        => ['required', 'integer', 'exists:shared_sites,id'],
            'attendance_date'=> ['required', 'date'],
            'status'         => ['required', 'in:absent,excused'],
            'absent_reason'  => ['required', 'string', 'max:100'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $this->verifyParticipantTenant($validated['participant_id'], $user->tenant_id);

        $attendance = DayCenterAttendance::updateOrCreate(
            [
                'tenant_id'       => $user->tenant_id,
                'participant_id'  => $validated['participant_id'],
                'site_id'         => $validated['site_id'],
                'attendance_date' => $validated['attendance_date'],
            ],
            [
                'status'              => $validated['status'],
                'check_in_time'       => null,
                'check_out_time'      => null,
                'absent_reason'       => $validated['absent_reason'],
                'notes'               => $validated['notes'] ?? null,
                'recorded_by_user_id' => $user->id,
            ]
        );

        AuditLog::record(
            action: 'day_center.absent',
            resourceType: 'DayCenterAttendance',
            resourceId: $attendance->id,
            userId: $user->id,
            tenantId: $user->tenant_id,
            newValues: ['status' => $validated['status'], 'reason' => $validated['absent_reason']],
        );

        return response()->json(['attendance' => $attendance]);
    }

    /**
     * GET /scheduling/day-center/summary
     * JSON: attendance counts per day for a date range — used for calendar heat-map.
     */
    public function summary(Request $request): JsonResponse
    {
        $user   = $request->user();
        $from   = $request->query('from', now()->startOfMonth()->toDateString());
        $to     = $request->query('to',   now()->toDateString());
        $siteId = (int) $request->query('site_id', $user->site_id);

        $rows = DayCenterAttendance::forTenant($user->tenant_id)
            ->forSite($siteId)
            ->whereBetween('attendance_date', [$from, $to])
            ->selectRaw('attendance_date, status, COUNT(*) as count')
            ->groupBy('attendance_date', 'status')
            ->get();

        // Pivot into { date: { present: N, absent: N, ... } }
        $summary = [];
        foreach ($rows as $row) {
            $d = $row->attendance_date->toDateString();
            $summary[$d][$row->status] = $row->count;
        }

        return response()->json(['summary' => $summary]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function authorizeManage(mixed $user): void
    {
        abort_unless(
            in_array($user->department, ['activities', 'it_admin', 'super_admin'])
            || $user->role === 'super_admin',
            403,
            'Only activities staff can manage day center attendance.'
        );
    }

    private function verifyParticipantTenant(int $participantId, int $tenantId): void
    {
        $participant = Participant::find($participantId);
        abort_if(!$participant || $participant->tenant_id !== $tenantId, 403);
    }
}
