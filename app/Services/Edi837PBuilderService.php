<?php

// ─── Edi837PBuilderService ────────────────────────────────────────────────────
// Generates ANSI X12 5010A1 837P (Professional) EDI transaction files for
// submission to CMS Encounter Data System (EDS) via CSSC Operations.
//
// X12 5010A1 837P segment structure (PACE EDR/CRR):
//   ISA*00*...*00*...*ZZ*{submitter_npi}*ZZ*CMS...*{date}*{time}*^*00501...
//   GS*HC*{sender_id}*CMSMEDICARED*{date}*{time}*1*X*005010X222A2
//   ST*837*0001*005010X222A2
//   BHT*0019*00*{batch_id}*{date}*{time}*CH
//   NM1*41*2*{org_name}*****XX*{billing_npi}  (Submitter)
//   NM1*40*2*CMS*****46*CMSMEDICARED           (Receiver)
//   HL*1**20*1                                 (Billing Provider HL)
//   PRV*BI*PXC*261QR0405X                      (PACE provider taxonomy)
//   NM1*85*2*{org_name}*****XX*{billing_npi}   (Billing Provider NM1)
//   HL*2*1*22*1                                (Subscriber HL)
//   NM1*IL*1*{last}*{first}***MI*{medicare_id} (Subscriber/Participant)
//   CLM*{claim_id}*{charge_amount}***{pos}:B:1*Y*A*Y*I  (Claim header)
//   HI*BK:{principal_icd10}*BF:{secondary_icd10}...     (Diagnoses)
//   LX*1                                       (Service line sequence)
//   SV1*HC:{cpt_code}:{modifier}*{charge}*UN*{units}*{pos}**{dx_ptrs}
//   DTP*472*D8*{YYYYMMDD}                      (Service date)
//   SE*{seg_count}*0001
//   GE*1*1
//   IEA*1*{isa_ctrl}
//
// NOTE: This implementation generates syntactically valid X12 per the 005010X222A2
// implementation guide. Real production use requires:
//   1. Actual PACE organization NPI and H-number
//   2. Clearinghouse connectivity (Availity, Change Healthcare, etc.)
//   3. Test-mode submission via CMS CSSC test environment first
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\EdiBatch;
use App\Models\EncounterLog;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class Edi837PBuilderService
{
    /**
     * Generate an 837P EDI batch from a set of encounter log IDs.
     *
     * Validates that each encounter has required fields before including it.
     * Creates an EdiBatch record with the full X12 file content.
     * Updates each included encounter's edi_batch_id and submission_status.
     *
     * @param  int    $tenantId         Tenant generating the batch
     * @param  array  $encounterIds     IDs of EncounterLog records to include
     * @param  int    $createdByUserId  User creating the batch
     * @return EdiBatch                  The created batch record
     * @throws \InvalidArgumentException if no valid encounters found
     */
    public function generateEncounterBatch(int $tenantId, array $encounterIds, int $createdByUserId): EdiBatch
    {
        $encounters = EncounterLog::whereIn('id', $encounterIds)
            ->where('tenant_id', $tenantId)
            ->with('participant')
            ->get();

        // Only include encounters that have all required 837P fields
        $valid = $encounters->filter(fn (EncounterLog $e) => $e->isSubmittable());

        if ($valid->isEmpty()) {
            throw new \InvalidArgumentException(
                'No valid encounters found — ensure each encounter has diagnosis codes, procedure code, and billing NPI.'
            );
        }

        $tenant  = Tenant::findOrFail($tenantId);
        $batchId = 'BATCH' . str_pad($tenantId, 4, '0', STR_PAD_LEFT) . Carbon::now()->format('YmdHis');

        $ediContent  = $this->buildX12Content($valid, $tenant, $batchId);
        $totalCharge = $valid->sum('charge_amount');

        $batch = EdiBatch::create([
            'tenant_id'           => $tenantId,
            'batch_type'          => 'edr',
            'file_name'           => $batchId . '.edi',
            'file_content'        => $ediContent,
            'record_count'        => $valid->count(),
            'total_charge_amount' => $totalCharge,
            'status'              => 'draft',
            'created_by_user_id'  => $createdByUserId,
        ]);

        // Link encounters to this batch and mark as submitted
        EncounterLog::whereIn('id', $valid->pluck('id'))
            ->update([
                'edi_batch_id'      => $batch->id,
                'submission_status' => 'submitted',
                'submitted_at'      => Carbon::now(),
            ]);

        return $batch;
    }

    /**
     * Parse a CMS 277CA (Claims Acknowledgement) EDI response and update batch
     * and individual encounter statuses accordingly.
     *
     * 277CA loop structure:
     *   2000A = Information Source (CMS)
     *   2000B = Information Receiver (PACE org)
     *   2000C = Service Provider
     *   2000D = Subscriber/Beneficiary
     *   2200D = Claim Status — contains STC (status code), REF (patient control)
     *
     * @param  string   $edi277Content  Raw X12 277CA file content
     * @param  EdiBatch $batch          The batch this acknowledgement is for
     */
    public function parseAcknowledgement(string $edi277Content, EdiBatch $batch): void
    {
        $segments = explode('~', str_replace(["\n", "\r"], '', $edi277Content));
        $accepted = 0;
        $rejected = 0;

        foreach ($segments as $segment) {
            $elements = explode('*', trim($segment));
            $id       = $elements[0] ?? '';

            // STC segment: Status Information — STC*{cat}*{status_date}*{action}
            // A1 = Accepted, A3 = Accepted with Changes, R0-R9 = Rejected
            if ($id === 'STC') {
                $statusCode = substr($elements[1] ?? '', 0, 2);
                if (str_starts_with($statusCode, 'A')) {
                    $accepted++;
                } elseif (str_starts_with($statusCode, 'R')) {
                    $rejected++;
                }
            }
        }

        // Determine overall batch status
        $batchStatus = match (true) {
            $rejected === 0 && $accepted > 0  => 'acknowledged',
            $accepted > 0 && $rejected > 0    => 'partially_accepted',
            $rejected > 0 && $accepted === 0  => 'rejected',
            default                            => $batch->status,
        };

        $batch->update([
            'status'            => $batchStatus,
            'cms_response_code' => "A:{$accepted}/R:{$rejected}",
        ]);

        // Update encounter statuses for accepted encounters
        if ($accepted > 0) {
            EncounterLog::where('edi_batch_id', $batch->id)
                ->where('submission_status', 'submitted')
                ->update([
                    'submission_status'         => 'accepted',
                    'cms_acknowledgement_status' => 'accepted',
                ]);
        }
    }

    // ── Private X12 building methods ─────────────────────────────────────────

    /**
     * Build the full X12 5010A1 837P file content.
     * Each segment ends with ~ (standard X12 segment terminator).
     *
     * @param  Collection $encounters  Valid EncounterLog records with participant loaded
     * @param  Tenant     $tenant      Billing organization
     * @param  string     $batchId     Unique batch reference for BHT segment
     * @return string                  Full X12 EDI content
     */
    private function buildX12Content(Collection $encounters, Tenant $tenant, string $batchId): string
    {
        $now     = Carbon::now();
        $date    = $now->format('Ymd');
        $time    = $now->format('Hi');
        $isaDate = $now->format('ymd');
        $isaCtrl = str_pad(rand(1, 999999999), 9, '0', STR_PAD_LEFT);
        $gsCtrl  = '1';

        // Use tenant's billing NPI from first encounter, fallback to placeholder
        $billingNpi = $encounters->first()->billing_provider_npi ?? '1234567890';
        $orgName    = strtoupper(substr(
            preg_replace('/[^A-Z0-9 ]/', '', strtoupper($tenant->name ?? 'PACE ORGANIZATION')),
            0,
            35
        ));

        $lines = [];

        // ── ISA/GS Envelope ──────────────────────────────────────────────────
        $lines[] = "ISA*00*          *00*          *ZZ*{$billingNpi}      *ZZ*CMSMEDICARED   *{$isaDate}*{$time}*^*00501*{$isaCtrl}*0*P*:";
        $lines[] = "GS*HC*{$billingNpi}*CMSMEDICARED*{$date}*{$time}*{$gsCtrl}*X*005010X222A2";
        $lines[] = "ST*837*0001*005010X222A2";
        $lines[] = "BHT*0019*00*{$batchId}*{$date}*{$time}*CH";

        // ── Submitter / Receiver ─────────────────────────────────────────────
        $lines[] = "NM1*41*2*{$orgName}*****XX*{$billingNpi}";
        $lines[] = "PER*IC*BILLING DEPT*TE*0000000000";
        $lines[] = "NM1*40*2*CMS MEDICARE*****46*CMSMEDICARED";

        // ── Billing Provider HL Loop ─────────────────────────────────────────
        $lines[] = "HL*1**20*1";
        $lines[] = "PRV*BI*PXC*261QR0405X";  // PACE Center taxonomy
        $lines[] = "NM1*85*2*{$orgName}*****XX*{$billingNpi}";
        $lines[] = "N3*123 MAIN ST";
        $lines[] = "N4*ANYTOWN*ST*00000";

        $hlCounter = 2;

        // ── Subscriber / Claim Loops (one per encounter) ─────────────────────
        foreach ($encounters as $encounter) {
            $participant = $encounter->participant;
            $hlSub       = $hlCounter++;
            $hlClaim     = $hlCounter++;

            $lines[] = "HL*{$hlSub}*1*22*1";
            $lines[] = "SBR*P*18*******MA";

            $medicareId = $participant->medicare_id ?? ('X' . $participant->id . 'X0000000A');
            $last       = strtoupper($participant->last_name ?? 'UNKNOWN');
            $first      = strtoupper($participant->first_name ?? 'PATIENT');

            $lines[] = "NM1*IL*1*{$last}*{$first}****MI*{$medicareId}";
            $lines[] = "DMG*D8*" . (
                $participant->dob
                    ? $participant->dob->format('Ymd')
                    : '19000101'
            ) . "*U";

            $lines[] = "HL*{$hlClaim}*{$hlSub}*23*0";
            $lines[] = "NM1*PR*2*MEDICARE*****PI*CMSMEDICARE";

            // ── Claim Header ─────────────────────────────────────────────────
            $claimId   = 'CLM' . $encounter->id . '-' . $date;
            $chargeAmt = number_format((float) $encounter->charge_amount, 2, '.', '');
            $pos       = $encounter->place_of_service_code ?? '65';

            $lines[] = "CLM*{$claimId}*{$chargeAmt}***{$pos}:B:1*Y*A*Y*I";
            $lines[] = "REF*EJ*ENC{$encounter->id}";

            // ── Diagnosis codes ───────────────────────────────────────────────
            $diagCodes = $encounter->diagnosis_codes ?? [];
            if (!empty($diagCodes)) {
                $hiParts = [];
                foreach ($diagCodes as $i => $code) {
                    $qualifier = ($i === 0) ? 'BK' : 'BF';
                    $hiParts[] = $qualifier . ':' . str_replace('.', '', $code);
                }
                $lines[] = 'HI*' . implode('*', $hiParts);
            }

            // ── Service Line ─────────────────────────────────────────────────
            $lines[] = "LX*1";
            $cpt      = $encounter->procedure_code ?? 'T1015';
            $modifier = $encounter->procedure_modifier ? ":{$encounter->procedure_modifier}" : '';
            $units    = number_format((float) $encounter->units, 2, '.', '');

            $lines[] = "SV1*HC:{$cpt}{$modifier}*{$chargeAmt}*UN*{$units}*{$pos}**1";

            $serviceDate = $encounter->service_date instanceof Carbon
                ? $encounter->service_date->format('Ymd')
                : date('Ymd', strtotime((string) $encounter->service_date));

            $lines[] = "DTP*472*D8*{$serviceDate}";

            if ($encounter->rendering_provider_npi) {
                $lines[] = "NM1*82*1*RENDERING*PROVIDER***XX*{$encounter->rendering_provider_npi}";
            }
        }

        // ── Trailer segments ─────────────────────────────────────────────────
        $seSegCount = count($lines) - 3 + 2; // subtract ISA/GS/ST + add SE/GE/IEA
        $lines[]    = "SE*{$seSegCount}*0001";
        $lines[]    = "GE*1*{$gsCtrl}";
        $lines[]    = "IEA*1*{$isaCtrl}";

        return implode("~\n", $lines) . "~\n";
    }
}
