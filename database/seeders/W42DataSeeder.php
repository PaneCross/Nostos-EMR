<?php

// ─── W42DataSeeder ─────────────────────────────────────────────────────────────
// Seeds demo BAA records and SRA records for the W4-2 Security & Compliance
// module. Called from DemoEnvironmentSeeder as part of the full demo seed.
//
// Data seeded:
//   3 BAA records:
//     - AWS (active, cloud provider — covers RDS + S3)
//     - Mailgun (expiring soon, IT services — no PHI, BAA as best practice)
//     - Placeholder Clearinghouse (pending — BLOCKER-05 narrative)
//
//   1 SRA record:
//     - Completed annual SRA (moderate risk, findings document the 3 open BLOCKERs)
//
// This data is intentionally realistic for demo purposes and demonstrates
// the compliance posture widget on the QA Dashboard.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\BaaRecord;
use App\Models\SraRecord;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class W42DataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'like', '%sunrise%')->first()
            ?? Tenant::first();

        if (! $tenant) {
            $this->command->warn('  W42DataSeeder: no tenant found, skipping.');
            return;
        }

        // ── BAA Records ───────────────────────────────────────────────────────

        // 1. AWS — active BAA covering cloud infrastructure
        BaaRecord::create([
            'tenant_id'           => $tenant->id,
            'vendor_name'         => 'Amazon Web Services (AWS)',
            'vendor_type'         => 'cloud_provider',
            'phi_accessed'        => true,
            'baa_signed_date'     => now()->subMonths(14)->toDateString(),
            'baa_expiration_date' => now()->addMonths(22)->toDateString(),
            'status'              => 'active',
            'contact_name'        => 'AWS Healthcare Compliance',
            'contact_email'       => 'aws-hipaa@amazon.com',
            'contact_phone'       => null,
            'notes'               => 'Covers EC2, RDS (PostgreSQL), S3, SES, and all HIPAA-eligible services. '
                . 'BAA obtained and managed via the AWS console (My Account > Agreements). '
                . 'Renewal is automatic — check AWS console before expiration.',
        ]);

        // 2. Mailgun — expiring soon (within 45 days)
        BaaRecord::create([
            'tenant_id'           => $tenant->id,
            'vendor_name'         => 'Mailgun Technologies (Sinch)',
            'vendor_type'         => 'it_services',
            'phi_accessed'        => false,
            'baa_signed_date'     => now()->subMonths(11)->toDateString(),
            'baa_expiration_date' => now()->addDays(45)->toDateString(),
            'status'              => 'expiring_soon',
            'contact_name'        => 'Mailgun Compliance Team',
            'contact_email'       => 'hipaa@mailgun.com',
            'contact_phone'       => null,
            'notes'               => 'No PHI transmitted via email (all notification emails contain zero PHI per design). '
                . 'BAA maintained as belt-and-suspenders best practice. '
                . 'ACTION: Renew before expiration — contact Mailgun Compliance for updated agreement.',
        ]);

        // 3. Clearinghouse — pending (BLOCKER-05 narrative)
        BaaRecord::create([
            'tenant_id'           => $tenant->id,
            'vendor_name'         => 'Healthcare Clearinghouse (TBD)',
            'vendor_type'         => 'clearinghouse',
            'phi_accessed'        => true,
            'baa_signed_date'     => null,
            'baa_expiration_date' => null,
            'status'              => 'pending',
            'contact_name'        => null,
            'contact_email'       => null,
            'contact_phone'       => null,
            'notes'               => 'BLOCKER-05: Clearinghouse vendor not yet selected. '
                . 'Candidates: Availity, Change Healthcare, Office Ally. '
                . 'BAA must be executed before enabling 837P live submission. '
                . 'Contact: Finance Director + Compliance Officer to initiate vendor selection.',
        ]);

        // ── SRA Record ────────────────────────────────────────────────────────

        // 1 completed annual SRA documenting current state
        SraRecord::create([
            'tenant_id'           => $tenant->id,
            'sra_date'            => now()->subMonths(3)->toDateString(),
            'conducted_by'        => 'Nostos IT / Compliance Team',
            'scope_description'   => 'Annual HIPAA Security Risk Analysis per 45 CFR §164.308(a)(1)(ii)(A). '
                . 'Scope: all ePHI workflows including participant records, clinical notes, medications, '
                . 'vitals, eMAR, care plans, billing data, FHIR API, and third-party integrations. '
                . 'Covered controls: access management, audit logging, encryption at rest and in transit, '
                . 'session security, workforce authorization, incident response, and contingency planning.',
            'risk_level'          => 'moderate',
            'findings_summary'    => "Key findings requiring remediation:\n"
                . "(1) Session encryption (SESSION_ENCRYPT) not enabled in production [BLOCKER-01]\n"
                . "(2) PostgreSQL sslmode='prefer' — must be changed to 'require' in production [BLOCKER-01]\n"
                . "(3) Documents stored on local disk without encryption — migrate to S3 with SSE-KMS [BLOCKER-01]\n"
                . "(4) No executed BAA with clearinghouse vendor pending BLOCKER-05 selection [BLOCKER-03]\n"
                . "(5) No formal pen test conducted yet — schedule before go-live [BLOCKER-03]\n"
                . "All other controls (RBAC, audit logging, OTP auth, session timeout, PHI field encryption) rated satisfactory.",
            'next_sra_due'        => now()->addMonths(9)->toDateString(),
            'status'              => 'completed',
            'reviewed_by_user_id' => null,
        ]);

        $this->command->line('  BAA records: <comment>3 created (AWS active, Mailgun expiring-soon, clearinghouse pending)</comment>');
        $this->command->line('  SRA records: <comment>1 completed annual SRA (moderate risk)</comment>');
    }
}
