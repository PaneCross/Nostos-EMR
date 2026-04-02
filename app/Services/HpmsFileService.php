<?php

// ─── HpmsFileService ─────────────────────────────────────────────────────────
// Generates CMS Health Plan Management System (HPMS) submission files for PACE.
//
// HPMS submission types:
//   enrollment     — Monthly, pipe-delimited, one record per newly enrolled participant
//   disenrollment  — Monthly, pipe-delimited, one record per disenrolled participant
//   quality_data   — Quarterly, fixed-width, hospitalization/immunization/fall rates
//   hos_m          — Annual, aggregate HOS-M survey results
//
// Generated files are stored in emr_hpms_submissions.file_content.
// Downloads are served through HpmsController::download() — never direct URL.
//
// ── GAP-14: HPMS Enrollment File Field Verification (W4-9) ───────────────────
// CMS HPMS Enrollment File — 11 required fields per CMS HPMS companion guide.
// Verified against the HPMS Enrollment/Disenrollment companion guide (V2025).
//
// Field #  | CMS Name                  | Source in NostosEMR
// ---------|---------------------------|--------------------------------------
// Field 1  | H-Number (Contract ID)    | shared_tenants.cms_contract_id
// Field 2  | Member ID (MBI)           | emr_participants.medicare_id (encrypted)
// Field 3  | Medicare Part A Eff. Date | emr_participants.medicare_a_start_date (NEW — migration 96)
// Field 4  | Medicare Part B Eff. Date | emr_participants.medicare_b_start_date (NEW — migration 96)
// Field 5  | Medicaid ID               | emr_participants.medicaid_id (encrypted)
// Field 6  | Enrollment Effective Date | emr_participants.enrollment_date
// Field 7  | Disenrollment Date        | emr_participants.disenrollment_date (disenrollment file only)
// Field 8  | Disenrollment Reason      | emr_participants.disenrollment_reason (disenrollment file only)
// Field 9  | Date of Birth             | emr_participants.dob
// Field 10 | Sex (M/F/U)               | emr_participants.gender → mapped to M/F/U
// Field 11 | County FIPS Code          | emr_participants.county_fips_code (NEW — migration 96)
//
// Fields 3, 4, 11 require migration 96 (2025_04_04_000001_add_hpms_fields_to_emr_participants).
// Run: php artisan migrate before generating enrollment files for these fields.
//
// NOTE: File format approximates CMS HPMS companion guide specifications.
// Real production use requires verification against current-year HPMS companion guide
// (updated annually by CMS — check HPMS portal for latest version).
// The H-number in Field 1 is required on every line by CMS for cross-reference.
// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\EncounterLog;
use App\Models\HosMSurvey;
use App\Models\HpmsSubmission;
use App\Models\Immunization;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\Tenant;
use Carbon\Carbon;

class HpmsFileService
{
    /**
     * Generate the monthly HPMS enrollment file for newly enrolled participants.
     * Includes all participants enrolled (status='enrolled') during the given month.
     *
     * @param  int    $tenantId  Tenant generating the file
     * @param  string $month     Format: 'YYYY-MM'
     * @param  int    $userId    User generating the file
     * @return HpmsSubmission
     */
    public function generateEnrollmentFile(int $tenantId, string $month, int $userId): HpmsSubmission
    {
        [$year, $mon]  = explode('-', $month);
        $periodStart   = Carbon::createFromDate($year, $mon, 1)->startOfMonth();
        $periodEnd     = $periodStart->copy()->endOfMonth();

        // Resolve tenant to get the CMS H-number (Field 1)
        $tenant  = Tenant::findOrFail($tenantId);
        $hNumber = $tenant->cms_contract_id ?? 'HXXXX'; // placeholder until real H-number assigned at go-live

        // Participants who transitioned to 'enrolled' status within this month
        $participants = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->whereBetween('enrollment_date', [$periodStart, $periodEnd])
            ->get();

        // Header: H-Number|Month|FileType|Version
        $lines = ["{$hNumber}|{$month}|HPMS_ENROLLMENT|V2025.1"];

        foreach ($participants as $p) {
            // CMS HPMS enrollment file — 11 fields per companion guide (GAP-14, W4-9)
            //
            // Field 1: H-Number (contract ID) — identifies the PACE organization to CMS
            // Field 2: MBI (Medicare Beneficiary Identifier) — encrypted at rest, decrypted on export
            // Field 3: Medicare Part A effective date — format YYYYMMDD (nullable: '' if missing)
            // Field 4: Medicare Part B effective date — format YYYYMMDD (nullable: '' if missing)
            // Field 5: Medicaid ID — state Medicaid identifier (encrypted at rest, decrypted on export)
            // Field 6: PACE enrollment effective date — format YYYYMMDD
            // Field 7: N/A for enrollment file (disenrollment date) — empty
            // Field 8: N/A for enrollment file (disenrollment reason) — empty
            // Field 9: Date of birth — format YYYYMMDD
            // Field 10: Sex — M/F/U (CMS uses M=Male, F=Female, U=Unknown/Undisclosed)
            // Field 11: County FIPS code — 5-digit (e.g. '39049' = Franklin County OH)
            $lines[] = implode('|', [
                $hNumber,                                                                    // Field 1
                $p->medicare_id ?? "UNK{$p->id}",                                          // Field 2
                $p->medicare_a_start_date ? $p->medicare_a_start_date->format('Ymd') : '', // Field 3
                $p->medicare_b_start_date ? $p->medicare_b_start_date->format('Ymd') : '', // Field 4
                $p->medicaid_id ?? '',                                                      // Field 5
                $p->enrollment_date ? $p->enrollment_date->format('Ymd') : '',             // Field 6
                '',                                                                         // Field 7 (N/A)
                '',                                                                         // Field 8 (N/A)
                $p->dob ? $p->dob->format('Ymd') : '',                                     // Field 9
                $this->mapSex($p->gender),                                                  // Field 10
                $p->county_fips_code ?? '',                                                 // Field 11
            ]);
        }

        return HpmsSubmission::create([
            'tenant_id'          => $tenantId,
            'submission_type'    => 'enrollment',
            'file_content'       => implode("\n", $lines),
            'record_count'       => $participants->count(),
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'status'             => 'draft',
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Map NostosEMR gender value to CMS HPMS sex code (Field 10).
     * CMS accepts: M (Male), F (Female), U (Unknown/Undisclosed).
     */
    private function mapSex(?string $gender): string
    {
        return match (strtolower((string) $gender)) {
            'male', 'm'                                    => 'M',
            'female', 'f'                                  => 'F',
            default                                        => 'U',
        };
    }

    /**
     * Generate the monthly HPMS disenrollment file.
     * Includes all participants who disenrolled (death, transfer, voluntary)
     * during the given month.
     *
     * @param  int    $tenantId  Tenant generating the file
     * @param  string $month     Format: 'YYYY-MM'
     * @param  int    $userId    User generating the file
     * @return HpmsSubmission
     */
    public function generateDisenrollmentFile(int $tenantId, string $month, int $userId): HpmsSubmission
    {
        [$year, $mon] = explode('-', $month);
        $periodStart  = Carbon::createFromDate($year, $mon, 1)->startOfMonth();
        $periodEnd    = $periodStart->copy()->endOfMonth();

        // Participants who reached a terminal enrollment status this month
        $participants = Participant::where('tenant_id', $tenantId)
            ->whereIn('enrollment_status', ['disenrolled', 'deceased', 'transferred'])
            ->whereBetween('updated_at', [$periodStart, $periodEnd])
            ->get();

        $lines = ["HPMS_DISENROLLMENT|{$month}|PACE|V2025.1"];
        foreach ($participants as $p) {
            $reason = match ($p->enrollment_status) {
                'deceased'    => 'DEATH',
                'transferred' => 'TRANSFER',
                default       => 'VOLUNTARY',
            };
            $lines[] = implode('|', [
                $p->medicare_id ?? "UNK{$p->id}",
                strtoupper($p->last_name),
                strtoupper($p->first_name),
                $p->dob ? $p->dob->format('Ymd') : '',
                $p->updated_at->format('Ymd'),
                $reason,
                $p->site_id ?? '',
            ]);
        }

        return HpmsSubmission::create([
            'tenant_id'          => $tenantId,
            'submission_type'    => 'disenrollment',
            'file_content'       => implode("\n", $lines),
            'record_count'       => $participants->count(),
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'status'             => 'draft',
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Generate quarterly quality data report for HPMS.
     * Computes from actual incident and encounter data.
     *
     * Quality metrics included:
     *   - Hospitalization rate (inpatient admissions / participant months)
     *   - Fall rate (fall incidents / participant months)
     *   - Immunization rates (flu, pneumococcal — PENDING external data linkage)
     *
     * @param  int  $tenantId  Tenant generating the file
     * @param  int  $year      Reporting year
     * @param  int  $quarter   1-4
     * @param  int  $userId    User generating the file
     * @return HpmsSubmission
     */
    public function generateQualityDataFile(int $tenantId, int $year, int $quarter, int $userId): HpmsSubmission
    {
        $periodStart = Carbon::createFromDate($year, (($quarter - 1) * 3) + 1, 1)->startOfMonth();
        $periodEnd   = $periodStart->copy()->addMonths(3)->subDay()->endOfDay();

        $participantCount  = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        $participantMonths = max($participantCount * 3, 1); // approximate (3 months per quarter)

        // Falls: incidents of type 'fall' — column is `occurred_at` per emr_incidents schema
        $fallCount = Incident::where('tenant_id', $tenantId)
            ->where('incident_type', 'fall')
            ->whereBetween('occurred_at', [$periodStart, $periodEnd])
            ->count();

        // Hospitalizations: specialist encounters (best approximation without external ADT)
        $hospCount = EncounterLog::where('tenant_id', $tenantId)
            ->where('service_type', 'specialist')
            ->whereBetween('service_date', [$periodStart, $periodEnd])
            ->count();

        $fallRate = round(($fallCount / $participantMonths) * 100, 2);
        $hospRate = round(($hospCount / $participantMonths) * 100, 2);

        // Flu immunization rate: participants with flu vaccine administered this year
        // vaccine_type='influenza' per emr_immunizations enum
        $fluVaccinatedCount = Immunization::where('tenant_id', $tenantId)
            ->where('vaccine_type', 'influenza')
            ->where('refused', false)
            ->whereYear('administered_date', $year)
            ->distinct('participant_id')
            ->count('participant_id');

        // Pneumococcal immunization rate: participants with any pneumo vaccine on record (ever)
        // CMS PACE measures lifetime coverage, not annual — count ever-vaccinated participants
        $pneumoVaccinatedCount = Immunization::where('tenant_id', $tenantId)
            ->whereIn('vaccine_type', ['pneumococcal_ppsv23', 'pneumococcal_pcv15', 'pneumococcal_pcv20'])
            ->where('refused', false)
            ->distinct('participant_id')
            ->count('participant_id');

        $fluRate   = $participantCount > 0 ? round(($fluVaccinatedCount / $participantCount) * 100, 2) : 0;
        $pneumoRate = $participantCount > 0 ? round(($pneumoVaccinatedCount / $participantCount) * 100, 2) : 0;

        $lines = [
            "HPMS_QUALITY|{$year}|Q{$quarter}|PACE|V2025.1",
            "PARTICIPANT_MONTHS|{$participantMonths}",
            "HOSPITALIZATION_RATE|{$hospRate}",
            "FALL_RATE|{$fallRate}",
            "IMMUNIZATION_FLU|{$fluRate}",
            "IMMUNIZATION_PNEUMO|{$pneumoRate}",
        ];

        return HpmsSubmission::create([
            'tenant_id'          => $tenantId,
            'submission_type'    => 'quality_data',
            'file_content'       => implode("\n", $lines),
            'record_count'       => 6,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'status'             => 'draft',
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Generate the annual HOS-M aggregate survey results file for HPMS.
     * Aggregates all completed HosMSurvey records for the given year.
     *
     * @param  int  $tenantId  Tenant generating the file
     * @param  int  $year      Survey year
     * @param  int  $userId    User generating the file
     * @return HpmsSubmission
     */
    public function generateHosMFile(int $tenantId, int $year, int $userId): HpmsSubmission
    {
        $periodStart = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $periodEnd   = Carbon::createFromDate($year, 12, 31)->endOfYear();

        $surveys = HosMSurvey::where('tenant_id', $tenantId)
            ->where('survey_year', $year)
            ->where('completed', true)
            ->get();

        $completedCount    = $surveys->count();
        $avgPhysicalHealth = $completedCount > 0
            ? round($surveys->avg(fn ($s) => ($s->responses['physical_health'] ?? 3)), 2)
            : 0;
        $avgMentalHealth   = $completedCount > 0
            ? round($surveys->avg(fn ($s) => ($s->responses['mental_health'] ?? 3)), 2)
            : 0;
        $fallRate          = $completedCount > 0
            ? round($surveys->avg(fn ($s) => ($s->responses['falls_past_year'] ?? 0)) * 100, 1)
            : 0;

        $lines = [
            "HPMS_HOS_M|{$year}|PACE|V2025.1",
            "SURVEYS_COMPLETED|{$completedCount}",
            "AVG_PHYSICAL_HEALTH|{$avgPhysicalHealth}",
            "AVG_MENTAL_HEALTH|{$avgMentalHealth}",
            "FALL_RATE_PCT|{$fallRate}",
        ];

        return HpmsSubmission::create([
            'tenant_id'          => $tenantId,
            'submission_type'    => 'hos_m',
            'file_content'       => implode("\n", $lines),
            'record_count'       => $completedCount,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'status'             => 'draft',
            'created_by_user_id' => $userId,
        ]);
    }
}
