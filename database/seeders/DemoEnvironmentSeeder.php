<?php

namespace Database\Seeders;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Database\Seeder;

class DemoEnvironmentSeeder extends Seeder
{
    private const DEPARTMENTS = [
        'primary_care'      => ['label' => 'Primary Care / Nursing',    'admin_first' => 'Margaret',  'standard_first' => 'Robert'],
        'therapies'         => ['label' => 'Therapies',                  'admin_first' => 'Patricia',  'standard_first' => 'James'],
        'social_work'       => ['label' => 'Social Work',                'admin_first' => 'Dorothy',   'standard_first' => 'Richard'],
        'behavioral_health' => ['label' => 'Behavioral Health',          'admin_first' => 'Gloria',    'standard_first' => 'Thomas'],
        'dietary'           => ['label' => 'Dietary / Nutrition',        'admin_first' => 'Helen',     'standard_first' => 'David'],
        'activities'        => ['label' => 'Activities / Recreation',    'admin_first' => 'Shirley',   'standard_first' => 'George'],
        'home_care'         => ['label' => 'Home Care',                  'admin_first' => 'Norma',     'standard_first' => 'Raymond'],
        'transportation'    => ['label' => 'Transportation',             'admin_first' => 'Barbara',   'standard_first' => 'Carlos'],
        'pharmacy'          => ['label' => 'Pharmacy',                   'admin_first' => 'Ruth',      'standard_first' => 'Harold'],
        'idt'               => ['label' => 'IDT / Care Coordination',   'admin_first' => 'Eleanor',   'standard_first' => 'Frank'],
        'enrollment'        => ['label' => 'Enrollment / Intake',        'admin_first' => 'Diane',     'standard_first' => 'Walter'],
        'finance'           => ['label' => 'Finance / Billing',          'admin_first' => 'Susan',     'standard_first' => 'William'],
        'qa_compliance'     => ['label' => 'QA / Compliance',            'admin_first' => 'Karen',     'standard_first' => 'Charles'],
        'it_admin'          => ['label' => 'IT / Administration',        'admin_first' => 'Nancy',     'standard_first' => 'Joseph'],
    ];

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  NostosEMR Demo Environment Seeder');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // ─── Tenant ───────────────────────────────────────────────────────────
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'sunrise-pace-demo'],
            [
                'name'               => 'Sunrise PACE — Demo Organization',
                'transport_mode'     => 'direct',
                'cms_contract_id'    => 'H9999',
                'state'              => 'CA',
                'timezone'           => 'America/Los_Angeles',
                'auto_logout_minutes'=> 15,
                'is_active'          => true,
            ]
        );
        $this->command->line("  Tenant: <comment>{$tenant->name}</comment>");

        // ─── Sites ────────────────────────────────────────────────────────────
        $eastSite = Site::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Sunrise PACE East'],
            [
                'mrn_prefix' => 'EAST',
                'address'    => '1200 E Harbor Blvd',
                'city'       => 'Long Beach',
                'state'      => 'CA',
                'zip'        => '90802',
                'phone'      => '(562) 555-0100',
                'is_active'  => true,
            ]
        );
        // Backfill mrn_prefix if site already existed without it
        if (! $eastSite->mrn_prefix) {
            $eastSite->update(['mrn_prefix' => 'EAST']);
        }

        $westSite = Site::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Sunrise PACE West'],
            [
                'mrn_prefix' => 'WEST',
                'address'    => '4400 W Century Blvd',
                'city'       => 'Inglewood',
                'state'      => 'CA',
                'zip'        => '90304',
                'phone'      => '(310) 555-0200',
                'is_active'  => true,
            ]
        );
        if (! $westSite->mrn_prefix) {
            $westSite->update(['mrn_prefix' => 'WEST']);
        }
        $this->command->line("  Sites: <comment>{$eastSite->name}</comment>, <comment>{$westSite->name}</comment>");

        // ─── Users (2 per department = 28 total) ─────────────────────────────
        $this->command->info('');
        $this->command->info('  Creating 28 demo users (2 per department)...');
        $this->command->info('');

        $headers = ['Department', 'Role', 'Email'];
        $rows    = [];

        $sites = [$eastSite, $westSite];

        foreach (self::DEPARTMENTS as $dept => $info) {
            foreach (['admin' => $info['admin_first'], 'standard' => $info['standard_first']] as $role => $firstName) {
                $email = strtolower($firstName) . '.' . $dept . '@sunrisepace-demo.test';

                User::firstOrCreate(
                    ['email' => $email],
                    [
                        'tenant_id'      => $tenant->id,
                        'site_id'        => $sites[array_rand($sites)]->id,
                        'first_name'     => $firstName,
                        'last_name'      => 'Demo',
                        'department'     => $dept,
                        'role'           => $role,
                        'is_active'      => true,
                        'provisioned_at' => now(),
                    ]
                );

                $rows[] = [$info['label'], ucfirst($role), $email];
            }
        }

        $this->command->table($headers, $rows);

        // ─── Super Admin ──────────────────────────────────────────────────────
        // tj@nostos.tech — full access via Google OAuth, no department restriction
        User::firstOrCreate(
            ['email' => 'tj@nostos.tech'],
            [
                'tenant_id'      => $tenant->id,
                'site_id'        => $eastSite->id,
                'first_name'     => 'TJ',
                'last_name'      => 'Nostos',
                'department'     => 'it_admin',
                'role'           => 'super_admin',
                'is_active'      => true,
                'provisioned_at' => now(),
            ]
        );
        $this->command->line('  Super Admin: <comment>tj@nostos.tech</comment> (role: super_admin — unrestricted access)');

        // ─── Permissions ──────────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding permission matrix...');
        $this->call(PermissionSeeder::class);

        // ─── Participants ─────────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding 30 demo participants...');
        $this->call(ParticipantSeeder::class);

        // ─── ICD-10 Reference Data (Phase 3) ──────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding ICD-10 reference codes...');
        $this->call(Icd10Seeder::class);

        // ─── Clinical Data (Phase 3) ──────────────────────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 3 clinical data (notes, vitals, assessments, problems, allergies, ADL)...');
        $this->call(ClinicalDataSeeder::class);

        // ─── Phase 4 Data (Care Plans, IDT Meetings, SDRs, Alerts) ───────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 4 data (care plans, IDT meetings, SDRs, alerts)...');
        $this->call(Phase4DataSeeder::class);

        // ─── Phase 5A Data (Locations + Appointments) ─────────────────────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 5A data (locations, appointments)...');
        $this->call(Phase5ADataSeeder::class);

        // ─── Phase 5B Data (Transport Requests + Manifest) ────────────────────
        $this->command->info('');
        $this->command->info('  Seeding Phase 5B data (transport requests, manifest run sheet)...');
        $this->call(Phase5BDataSeeder::class);

        // ─── Phase 5C Data (Medications Reference + Participant Medications) ──
        $this->command->info('');
        $this->command->info('  Seeding Phase 5C data (medications reference, participant meds)...');
        $this->call(MedicationsReferenceSeeder::class);
        $this->call(Phase5CDataSeeder::class);

        // ─── Phase 7C: Chat Channels ───────────────────────────────────────────
        // Auto-create 14 department channels + 1 broadcast channel for the tenant.
        // The super-admin (tj@nostos.tech) is used as the "created_by" user.
        $this->command->info('');
        $this->command->info('  Seeding Phase 7C data (chat channels)...');

        $createdBy = User::where('email', 'tj@nostos.tech')->first()
            ?? User::where('tenant_id', $tenant->id)->first();

        if ($createdBy) {
            app(ChatService::class)->createDepartmentChannels($tenant->id, $createdBy);
            $this->command->line('  Chat channels: <comment>14 department + 1 broadcast</comment>');
        }

        // ─── Phase 7D: Demo Polish Data ───────────────────────────────────────
        // Adds scenario-specific data: unsigned notes >24h, care plans due soon,
        // fall incident (RCA), enrollment referrals, chat seed messages, no-show.
        $this->command->info('');
        $this->command->info('  Seeding Phase 7D demo polish data...');
        $this->call(Phase7DDataSeeder::class);

        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  Demo environment ready!');
        $this->command->info('  Login at: http://localhost/login');
        $this->command->info('  OTP emails: http://localhost:8025 (Mailpit)');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('');
    }
}
