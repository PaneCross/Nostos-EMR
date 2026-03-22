<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }

    public function test_audit_log_can_be_created(): void
    {
        AuditLog::record(
            action: 'test_event',
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            description: 'Test log entry',
        );

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'  => 'test_event',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_audit_log_cannot_be_updated_at_model_level(): void
    {
        $log = AuditLog::record(action: 'original_action', tenantId: $this->tenant->id);

        $this->expectException(ImmutableRecordException::class);

        $log->action = 'tampered';
        $log->save();
    }

    public function test_audit_log_delete_throws_immutable_exception(): void
    {
        $log = AuditLog::record(action: 'to_delete', tenantId: $this->tenant->id);

        $this->expectException(ImmutableRecordException::class);

        $log->delete();
    }

    public function test_audit_log_cannot_be_deleted_via_db_rule(): void
    {
        $log = AuditLog::record(action: 'db_rule_test', tenantId: $this->tenant->id);
        $id  = $log->id;

        // Attempt raw DB delete — PostgreSQL rule should silently reject it
        \Illuminate\Support\Facades\DB::table('shared_audit_logs')->where('id', $id)->delete();

        $this->assertDatabaseHas('shared_audit_logs', ['id' => $id]);
    }

    public function test_audit_log_cannot_be_updated_via_db_rule(): void
    {
        $log = AuditLog::record(action: 'original', tenantId: $this->tenant->id);

        // Attempt raw DB update — PostgreSQL rule should silently reject it
        \Illuminate\Support\Facades\DB::table('shared_audit_logs')
            ->where('id', $log->id)
            ->update(['action' => 'tampered']);

        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'original']);
    }

    public function test_login_event_is_logged(): void
    {
        // Login event is tested in AuthTest but confirm structure here
        AuditLog::record(
            action: 'login',
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            description: 'Successful OTP login',
            newValues: ['ip' => '127.0.0.1'],
        );

        $log = AuditLog::where('action', 'login')->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->user->id, $log->user_id);
        $this->assertEquals('127.0.0.1', $log->new_values['ip']);
    }
}
