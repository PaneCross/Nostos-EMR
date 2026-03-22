<?php

// ─── NotificationTest ─────────────────────────────────────────────────────────
// Feature tests for notification delivery via NotificationDispatcher.
//
// Coverage:
//   - Critical alert with email_immediate pref sends NotificationMail immediately
//   - Alert with in_app_only pref does NOT send email
//   - Alert with 'off' pref does nothing
//   - Digest preference queues in cache (not immediate email)
//   - DigestNotificationJob sends DigestNotificationMail for pending digest users
//   - DigestNotificationJob clears cache counter after sending
//   - Notification mail subject contains NO PHI
//   - Digest mail subject contains NO PHI
//   - Profile notification preferences update endpoint saves correctly
//   - Unknown pref keys are rejected (422 validation)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\DigestNotificationJob;
use App\Mail\DigestNotificationMail;
use App\Mail\NotificationMail;
use App\Models\Alert;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeUserWithPref(string $dept, string $prefKey, string $prefValue): User
    {
        $tenant = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id'               => $tenant->id,
            'department'              => $dept,
            'is_active'               => true,
            'notification_preferences'=> [$prefKey => $prefValue],
        ]);
    }

    private function makeAlert(User $user, string $severity = 'critical'): Alert
    {
        return Alert::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'severity'           => $severity,
            'target_departments' => [$user->department],
            'is_active'          => true,
        ]);
    }

    // ── email_immediate ───────────────────────────────────────────────────────

    public function test_critical_alert_with_email_immediate_sends_mail(): void
    {
        Mail::fake();

        $user  = $this->makeUserWithPref('idt', 'alert_critical', 'email_immediate');
        $alert = $this->makeAlert($user, 'critical');

        app(NotificationDispatcher::class)->dispatch($alert);

        Mail::assertQueued(NotificationMail::class, function ($mail) use ($user) {
            return $mail->recipient->id === $user->id;
        });
    }

    public function test_notification_mail_subject_contains_no_phi(): void
    {
        $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $mail = new NotificationMail($user);
        $envelope = $mail->envelope();

        $subject = $envelope->subject;
        $this->assertStringNotContainsStringIgnoringCase('patient', $subject);
        $this->assertStringNotContainsStringIgnoringCase('diagnosis', $subject);
        $this->assertStringNotContainsStringIgnoringCase('medication', $subject);
        // Subject must be generic
        $this->assertStringContainsString('NostosEMR', $subject);
    }

    // ── in_app_only ───────────────────────────────────────────────────────────

    public function test_in_app_only_pref_does_not_send_email(): void
    {
        Mail::fake();

        $user  = $this->makeUserWithPref('idt', 'alert_critical', 'in_app_only');
        $alert = $this->makeAlert($user, 'critical');

        app(NotificationDispatcher::class)->dispatch($alert);

        Mail::assertNothingQueued();
    }

    // ── off ───────────────────────────────────────────────────────────────────

    public function test_off_pref_skips_all_delivery(): void
    {
        Mail::fake();
        Cache::flush();

        $user  = $this->makeUserWithPref('idt', 'alert_critical', 'off');
        $alert = $this->makeAlert($user, 'critical');

        app(NotificationDispatcher::class)->dispatch($alert);

        Mail::assertNothingQueued();
        $this->assertNull(Cache::get("digest_pending:{$user->id}"));
    }

    // ── email_digest + DigestNotificationJob ─────────────────────────────────

    public function test_digest_pref_adds_to_cache_without_immediate_email(): void
    {
        Mail::fake();
        Cache::flush();

        $user  = $this->makeUserWithPref('idt', 'alert_warning', 'email_digest');
        $alert = $this->makeAlert($user, 'warning');

        app(NotificationDispatcher::class)->dispatch($alert);

        // No immediate email
        Mail::assertNothingQueued();

        // But cache counter incremented
        $this->assertGreaterThan(0, (int) Cache::get("digest_pending:{$user->id}", 0));
    }

    public function test_digest_job_sends_digest_mail_and_clears_counter(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'tenant_id'  => Tenant::factory()->create()->id,
            'is_active'  => true,
        ]);

        // Manually set a pending digest count
        Cache::put("digest_pending:{$user->id}", 3);

        (new DigestNotificationJob())->handle();

        Mail::assertQueued(DigestNotificationMail::class, function ($mail) use ($user) {
            return $mail->recipient->id === $user->id && $mail->count === 3;
        });

        // Counter should be cleared
        $this->assertEquals(0, (int) Cache::get("digest_pending:{$user->id}", 0));
    }

    public function test_digest_job_does_not_send_when_no_pending(): void
    {
        Mail::fake();
        Cache::flush();

        $user = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'is_active' => true,
        ]);

        (new DigestNotificationJob())->handle();

        Mail::assertNothingQueued();
    }

    public function test_digest_mail_subject_contains_no_phi(): void
    {
        $user = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $mail = new DigestNotificationMail($user, 5);
        $envelope = $mail->envelope();

        $subject = $envelope->subject;
        $this->assertStringNotContainsStringIgnoringCase('patient', $subject);
        $this->assertStringNotContainsStringIgnoringCase('diagnosis', $subject);
        $this->assertStringContainsString('NostosEMR', $subject);
        // Count is in the subject
        $this->assertStringContainsString('5', $subject);
    }

    // ── fallback ──────────────────────────────────────────────────────────────

    public function test_missing_pref_falls_back_to_in_app_only(): void
    {
        Mail::fake();

        // User with empty preferences
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create([
            'tenant_id'               => $tenant->id,
            'department'              => 'idt',
            'is_active'               => true,
            'notification_preferences'=> [],
        ]);
        $alert  = $this->makeAlert($user, 'critical');

        app(NotificationDispatcher::class)->dispatch($alert);

        // Default = in_app_only → no email
        Mail::assertNothingQueued();
    }

    // ── Notification preferences API ──────────────────────────────────────────

    public function test_user_can_update_notification_preferences(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->putJson('/profile/notifications', [
                'preferences' => ['alert_critical' => 'email_immediate'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $user->refresh();
        $this->assertEquals('email_immediate', $user->notificationPreference('alert_critical'));
    }

    public function test_invalid_preference_value_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->putJson('/profile/notifications', [
                'preferences' => ['alert_critical' => 'send_pager'],
            ])
            ->assertUnprocessable();
    }

    public function test_unauthenticated_cannot_update_preferences(): void
    {
        $this->putJson('/profile/notifications', ['preferences' => []])
            ->assertUnauthorized();
    }
}
