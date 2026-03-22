<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUDIT A — Verifies that the shared_audit_logs table and AuditLog model
 * are truly append-only, as required by HIPAA audit-trail integrity rules.
 *
 * Two layers of protection:
 *  1. Model level: save() / delete() throw ImmutableRecordException
 *  2. DB level:    PostgreSQL rules silently reject raw UPDATE / DELETE statements
 */
class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User   $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }

    // ── Model-level immutability ───────────────────────────────────────────────

    /** @test */
    public function update_existing_audit_log_throws_immutable_record_exception(): void
    {
        $log = AuditLog::record(action: 'original', tenantId: $this->tenant->id);

        $this->expectException(ImmutableRecordException::class);
        $this->expectExceptionMessageMatches('/immutable/i');

        $log->action = 'hacked';
        $log->save();
    }

    /** @test */
    public function delete_audit_log_throws_immutable_record_exception(): void
    {
        $log = AuditLog::record(action: 'will_not_delete', tenantId: $this->tenant->id);
        $id  = $log->id;

        $this->expectException(ImmutableRecordException::class);

        $log->delete();

        // Never reached but here as documentation
        $this->assertDatabaseHas('shared_audit_logs', ['id' => $id]);
    }

    /** @test */
    public function new_audit_log_creation_does_not_throw(): void
    {
        // Inserting a new record must NOT throw
        $log = AuditLog::record(
            action:       'new_entry',
            tenantId:     $this->tenant->id,
            userId:       $this->user->id,
            resourceType: 'App\\Models\\Participant',
            resourceId:   42,
            description:  'Created for immutability test',
        );

        $this->assertNotNull($log->id);
        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'new_entry',
            'resource_type' => 'App\\Models\\Participant',
            'resource_id'   => 42,
        ]);
    }

    // ── DB-level immutability (PostgreSQL rules) ───────────────────────────────

    /** @test */
    public function raw_db_update_is_blocked_by_postgresql_rule(): void
    {
        $log = AuditLog::record(action: 'protected', tenantId: $this->tenant->id);

        // PostgreSQL rule silently ignores this UPDATE
        DB::table('shared_audit_logs')
            ->where('id', $log->id)
            ->update(['action' => 'tampered_via_raw_sql']);

        $this->assertDatabaseHas('shared_audit_logs', ['action' => 'protected']);
        $this->assertDatabaseMissing('shared_audit_logs', ['action' => 'tampered_via_raw_sql']);
    }

    /** @test */
    public function raw_db_delete_is_blocked_by_postgresql_rule(): void
    {
        $log = AuditLog::record(action: 'protected_delete', tenantId: $this->tenant->id);
        $id  = $log->id;

        // PostgreSQL rule silently ignores this DELETE
        DB::table('shared_audit_logs')->where('id', $id)->delete();

        $this->assertDatabaseHas('shared_audit_logs', ['id' => $id]);
    }

    // ── Exception class properties ─────────────────────────────────────────────

    /** @test */
    public function immutable_record_exception_message_includes_model_name(): void
    {
        $e = new ImmutableRecordException('AuditLog');
        $this->assertStringContainsString('AuditLog', $e->getMessage());
        $this->assertStringContainsString('immutable', $e->getMessage());
    }
}
