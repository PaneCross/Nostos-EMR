<?php

// ─── EncryptionTest ───────────────────────────────────────────────────────────
// Verifies W4-2 encryption-at-rest implementation.
//
// Covers:
//   1. Field-level PHI encryption — Participant (medicare_id, medicaid_id,
//      ssn_last_four) and InsuranceCoverage (member_id, bin_pcn) are stored
//      as AES-256-CBC ciphertext via Laravel's 'encrypted' model cast.
//   2. Eloquent cast transparency — encrypted fields decrypt back to the
//      original plain-text value when read through the model.
//   3. Security page access control — only it_admin can view encryption status.
//   4. Encryption status prop — the Security page Inertia response includes
//      the encryption_status checks block (from SecurityComplianceController).
//
// HIPAA reference: 45 CFR §164.312(a)(2)(iv) — Encryption and decryption.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\InsuranceCoverage;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EncryptionTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Create a provisioned IT Admin user (has access to /it-admin/security) */
    private function itAdminUser(): User
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);

        return User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'it_admin',
            'role'       => 'admin',
        ]);
    }

    /**
     * Create a participant with known PHI field values for raw-query assertions.
     * Overrides default factory values so assertions are deterministic.
     */
    private function participantWithPhi(int $tenantId, int $siteId): Participant
    {
        return Participant::factory()->create([
            'tenant_id'    => $tenantId,
            'site_id'      => $siteId,
            'medicare_id'  => 'HICN123456789A',
            'medicaid_id'  => 'MEDI0987654321',
            'ssn_last_four'=> '4321',
        ]);
    }

    // ── Field-level encryption: Participant model ─────────────────────────────

    /**
     * medicare_id must be stored as AES-256-CBC ciphertext, not plain text.
     * HIPAA §164.312(a)(2)(iv): ePHI must be encrypted at rest.
     */
    public function test_medicare_id_is_stored_as_ciphertext_in_database(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $raw = DB::selectOne('SELECT medicare_id FROM emr_participants WHERE id = ?', [$p->id]);

        $this->assertNotNull($raw->medicare_id, 'medicare_id should not be null');
        $this->assertNotEquals('HICN123456789A', $raw->medicare_id,
            'medicare_id must be stored as encrypted ciphertext, not plain text');
    }

    /**
     * The 'encrypted' Eloquent cast must transparently decrypt medicare_id
     * back to the original value when reading through the model.
     */
    public function test_medicare_id_decrypts_correctly_via_eloquent(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $fresh = Participant::find($p->id);

        $this->assertEquals('HICN123456789A', $fresh->medicare_id,
            'Eloquent encrypted cast must decrypt medicare_id to the original value');
    }

    /**
     * ssn_last_four must be stored as ciphertext.
     * Even partial SSN constitutes PHI under HIPAA Safe Harbor de-identification.
     */
    public function test_ssn_last_four_is_stored_as_ciphertext(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $raw = DB::selectOne('SELECT ssn_last_four FROM emr_participants WHERE id = ?', [$p->id]);

        $this->assertNotEquals('4321', $raw->ssn_last_four,
            'ssn_last_four must be stored as ciphertext, not plain text');
    }

    /**
     * ssn_last_four must decrypt correctly via the Eloquent model.
     */
    public function test_ssn_last_four_decrypts_correctly_via_eloquent(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $fresh = Participant::find($p->id);
        $this->assertEquals('4321', $fresh->ssn_last_four);
    }

    /**
     * medicaid_id must be stored as ciphertext.
     */
    public function test_medicaid_id_is_stored_as_ciphertext(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $raw = DB::selectOne('SELECT medicaid_id FROM emr_participants WHERE id = ?', [$p->id]);

        $this->assertNotEquals('MEDI0987654321', $raw->medicaid_id,
            'medicaid_id must be stored as ciphertext, not plain text');
    }

    // ── Field-level encryption: InsuranceCoverage model ──────────────────────

    /**
     * InsuranceCoverage.member_id must be stored as ciphertext.
     * Payer member IDs are PHI (they identify the participant to their insurer).
     */
    public function test_insurance_member_id_is_stored_as_ciphertext(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $coverage = InsuranceCoverage::create([
            'participant_id' => $p->id,
            'payer_type'     => 'medicare_a',
            'member_id'      => 'MEM987654321',
            'bin_pcn'        => 'BIN001234',
            'is_active'      => true,
        ]);

        $raw = DB::selectOne(
            'SELECT member_id FROM emr_insurance_coverages WHERE id = ?',
            [$coverage->id]
        );

        $this->assertNotEquals('MEM987654321', $raw->member_id,
            'member_id must be stored as ciphertext, not plain text');
    }

    /**
     * InsuranceCoverage.bin_pcn must be stored as ciphertext.
     */
    public function test_insurance_bin_pcn_is_stored_as_ciphertext(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $coverage = InsuranceCoverage::create([
            'participant_id' => $p->id,
            'payer_type'     => 'medicare_a',
            'member_id'      => 'MEM000001',
            'bin_pcn'        => 'BIN001234',
            'is_active'      => true,
        ]);

        $raw = DB::selectOne(
            'SELECT bin_pcn FROM emr_insurance_coverages WHERE id = ?',
            [$coverage->id]
        );

        $this->assertNotEquals('BIN001234', $raw->bin_pcn,
            'bin_pcn must be stored as ciphertext, not plain text');
    }

    /**
     * Both member_id and bin_pcn must decrypt correctly via Eloquent.
     */
    public function test_insurance_encrypted_fields_decrypt_via_eloquent(): void
    {
        $user = $this->itAdminUser();
        $p    = $this->participantWithPhi($user->tenant_id, $user->site_id);

        $coverage = InsuranceCoverage::create([
            'participant_id' => $p->id,
            'payer_type'     => 'medicare_a',
            'member_id'      => 'MEM987654321',
            'bin_pcn'        => 'BIN001234',
            'is_active'      => true,
        ]);

        $fresh = InsuranceCoverage::find($coverage->id);
        $this->assertEquals('MEM987654321', $fresh->member_id);
        $this->assertEquals('BIN001234', $fresh->bin_pcn);
    }

    // ── Security page access control ──────────────────────────────────────────

    /**
     * GET /it-admin/security requires it_admin department.
     * Non-IT-admin users must receive 403 Forbidden.
     */
    public function test_security_page_requires_it_admin_department(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $qaUser = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
        ]);

        $this->actingAs($qaUser)->get('/it-admin/security')->assertForbidden();
    }

    /**
     * GET /it-admin/security (it_admin) must return Inertia page with
     * encryption_status prop containing the 4 W4-2 checks.
     */
    public function test_security_page_includes_encryption_status_checks(): void
    {
        $user = $this->itAdminUser();

        $response = $this->actingAs($user)->get('/it-admin/security');

        $response->assertOk();
        // Inertia prop is camelCase 'encryptionStatus'; keys are session/db_ssl/field_encryption/storage
        $response->assertInertia(fn ($page) =>
            $page->component('ItAdmin/Security')
                 ->has('encryptionStatus')
                 ->has('encryptionStatus.field_encryption')
        );
    }

    /**
     * The field_encryption check must have status='pass' because the W4-2
     * implementation added the 'encrypted' cast to Participant::$casts['medicare_id'].
     */
    public function test_field_encryption_check_is_pass_after_w42(): void
    {
        $user = $this->itAdminUser();

        $response = $this->actingAs($user)->get('/it-admin/security');

        // Inertia prop: encryptionStatus.field_encryption.status (not nested under 'checks')
        $response->assertInertia(fn ($page) =>
            $page->where('encryptionStatus.field_encryption.status', 'pass')
        );
    }
}
