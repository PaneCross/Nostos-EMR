<?php

// ─── NotificationDispatcherTest ───────────────────────────────────────────────
// Unit tests for NotificationDispatcher service.
//
// Coverage:
//   - preferenceKey() maps severity → pref key correctly
//   - deliverTo() with email_immediate → queues NotificationMail
//   - deliverTo() with in_app_only → no mail queued
//   - deliverTo() with off → no mail, no cache
//   - deliverTo() with email_digest → increments cache counter, no mail
//   - deliverTo() with unknown/custom pref → treated as in_app_only (no mail)
//   - dispatch() delivers to ALL users in target departments
//   - dispatch() skips inactive users
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Mail\NotificationMail;
use App\Models\Alert;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = app(NotificationDispatcher::class);
    }

    // ── preferenceKey() ───────────────────────────────────────────────────────

    public function test_preference_key_maps_critical_correctly(): void
    {
        $this->assertEquals('alert_critical', $this->dispatcher->preferenceKey('critical'));
    }

    public function test_preference_key_maps_warning_correctly(): void
    {
        $this->assertEquals('alert_warning', $this->dispatcher->preferenceKey('warning'));
    }

    public function test_preference_key_maps_info_correctly(): void
    {
        $this->assertEquals('alert_info', $this->dispatcher->preferenceKey('info'));
    }

    // ── deliverTo() ───────────────────────────────────────────────────────────

    public function test_email_immediate_queues_mail(): void
    {
        Mail::fake();

        $user = $this->makeUser(['alert_critical' => 'email_immediate']);
        $this->dispatcher->deliverTo($user, 'alert_critical');

        Mail::assertQueued(NotificationMail::class, fn ($m) => $m->recipient->id === $user->id);
    }

    public function test_in_app_only_queues_no_mail(): void
    {
        Mail::fake();

        $user = $this->makeUser(['alert_critical' => 'in_app_only']);
        $this->dispatcher->deliverTo($user, 'alert_critical');

        Mail::assertNothingQueued();
    }

    public function test_off_queues_no_mail_and_no_cache(): void
    {
        Mail::fake();
        Cache::flush();

        $user = $this->makeUser(['alert_critical' => 'off']);
        $this->dispatcher->deliverTo($user, 'alert_critical');

        Mail::assertNothingQueued();
        $this->assertNull(Cache::get("digest_pending:{$user->id}"));
    }

    public function test_email_digest_increments_cache_without_mail(): void
    {
        Mail::fake();
        Cache::flush();

        $user = $this->makeUser(['alert_warning' => 'email_digest']);
        $this->dispatcher->deliverTo($user, 'alert_warning');

        Mail::assertNothingQueued();
        $this->assertGreaterThan(0, (int) Cache::get("digest_pending:{$user->id}", 0));
    }

    public function test_unknown_preference_falls_back_to_in_app_only(): void
    {
        Mail::fake();

        $user = $this->makeUser([]);  // Empty prefs → fallback
        $this->dispatcher->deliverTo($user, 'alert_critical');

        Mail::assertNothingQueued();
    }

    // ── dispatch() ────────────────────────────────────────────────────────────

    public function test_dispatch_delivers_to_all_target_dept_users(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();

        // Two IDT users, both prefer email_immediate
        $userA = User::factory()->create([
            'tenant_id'               => $tenant->id,
            'department'              => 'idt',
            'is_active'               => true,
            'notification_preferences'=> ['alert_critical' => 'email_immediate'],
        ]);
        $userB = User::factory()->create([
            'tenant_id'               => $tenant->id,
            'department'              => 'idt',
            'is_active'               => true,
            'notification_preferences'=> ['alert_critical' => 'email_immediate'],
        ]);

        $alert = Alert::factory()->create([
            'tenant_id'          => $tenant->id,
            'severity'           => 'critical',
            'target_departments' => ['idt'],
        ]);

        $this->dispatcher->dispatch($alert);

        Mail::assertQueued(NotificationMail::class, 2);
    }

    public function test_dispatch_skips_inactive_users(): void
    {
        Mail::fake();

        $tenant      = Tenant::factory()->create();
        $activeUser  = User::factory()->create([
            'tenant_id'               => $tenant->id,
            'department'              => 'idt',
            'is_active'               => true,
            'notification_preferences'=> ['alert_critical' => 'email_immediate'],
        ]);
        $inactiveUser = User::factory()->create([
            'tenant_id'               => $tenant->id,
            'department'              => 'idt',
            'is_active'               => false,
            'notification_preferences'=> ['alert_critical' => 'email_immediate'],
        ]);

        $alert = Alert::factory()->create([
            'tenant_id'          => $tenant->id,
            'severity'           => 'critical',
            'target_departments' => ['idt'],
        ]);

        $this->dispatcher->dispatch($alert);

        // Only active user gets the mail
        Mail::assertQueued(NotificationMail::class, 1);
        Mail::assertQueued(NotificationMail::class, fn ($m) => $m->recipient->id === $activeUser->id);
    }

    public function test_dispatch_with_empty_target_departments_does_nothing(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $alert  = Alert::factory()->create([
            'tenant_id'          => $tenant->id,
            'target_departments' => [],
        ]);

        $this->dispatcher->dispatch($alert);

        Mail::assertNothingQueued();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeUser(array $prefs): User
    {
        return User::factory()->create([
            'tenant_id'               => Tenant::factory()->create()->id,
            'is_active'               => true,
            'notification_preferences'=> $prefs,
        ]);
    }
}
