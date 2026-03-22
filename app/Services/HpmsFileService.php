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
// NOTE: File formats are approximations of CMS HPMS companion guide specifications.
// Real production use requires verification against current-year HPMS companion guide
// (updated annually by CMS — check HPMS portal for latest version).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\EncounterLog;
use App\Models\HosMSurvey;
use App\Models\HpmsSubmission;
use App\Models\Immunization;
use App\Models\Incident;
use App\Models\Participant;
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

        // Participants who transitioned to 'enrolled' status within this month
        $participants = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->whereBetween('enrollment_date', [$periodStart, $periodEnd])
            ->get();

        $lines = ["HPMS_ENROLLMENT|{$month}|PACE|V2025.1"];
        foreach ($participants as $p) {
            // Format: MedicareID|LastName|FirstName|DOB(YYYYMMDD)|EnrollDate|SiteCode|PACEOrgNPI
            $lines[] = implode('|', [
                $p->medicare_id ?? "UNK{$p->id}",
                strtoupper($p->last_name),
                strtoupper($p->first_name),
                $p->dob ? $p->dob->format('Ymd') : '',
                $p->enrollment_date ? Carbon::parse($p->enrollment_date)->format('Ymd') : '',
                $p->site_id ?? '',
                '1234567890', // placeholder PACE org NPI — replace with real NPI at go-live
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
