<?php

// ─── W4-2: Widen encrypted PHI columns to TEXT ───────────────────────────────
// The W4-2 phase added Laravel 'encrypted' cast to:
//   - Participant: ssn_last_four, medicare_id, medicaid_id
//   - InsuranceCoverage: member_id, bin_pcn
//
// AES-256-CBC ciphertext (with IV + MAC, base64-encoded) is ~180–220 chars.
// The original column widths (varchar 4 / 20 / 50) are far too narrow.
// This migration widens all five columns to TEXT (unlimited) to accommodate
// the encrypted values.
//
// HIPAA §164.312(a)(2)(iv): Encryption at rest requires that ciphertext is
// stored durably — a truncated ciphertext is an unrecoverable data loss.
//
// Rolling back this migration while the 'encrypted' casts remain on the models
// would cause data loss. Only roll back after removing the casts from the models.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Participant — ssn_last_four was varchar(4), medicare/medicaid were varchar(20)
        DB::statement('ALTER TABLE emr_participants ALTER COLUMN ssn_last_four TYPE TEXT');
        DB::statement('ALTER TABLE emr_participants ALTER COLUMN medicare_id TYPE TEXT');
        DB::statement('ALTER TABLE emr_participants ALTER COLUMN medicaid_id TYPE TEXT');

        // InsuranceCoverage — member_id and bin_pcn were varchar(50)
        DB::statement('ALTER TABLE emr_insurance_coverages ALTER COLUMN member_id TYPE TEXT');
        DB::statement('ALTER TABLE emr_insurance_coverages ALTER COLUMN bin_pcn TYPE TEXT');
    }

    public function down(): void
    {
        // NOTE: Only run down() after removing 'encrypted' casts from models.
        // Rolling back with casts still in place will truncate ciphertext and corrupt data.
        DB::statement('ALTER TABLE emr_participants ALTER COLUMN ssn_last_four TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE emr_participants ALTER COLUMN medicare_id TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE emr_participants ALTER COLUMN medicaid_id TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE emr_insurance_coverages ALTER COLUMN member_id TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE emr_insurance_coverages ALTER COLUMN bin_pcn TYPE VARCHAR(255)');
    }
};
