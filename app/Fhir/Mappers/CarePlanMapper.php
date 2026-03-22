<?php

// ─── CarePlanMapper ───────────────────────────────────────────────────────────
// Maps a NostosEMR CarePlan to a FHIR R4 CarePlan resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/careplan.html
//
// Status mapping:
//   draft        → draft
//   active       → active
//   under_review → active (in review = currently being acted on)
//   archived     → revoked (superseded by a newer version)
//
// Care plan goals are included as contained resources or as goal summaries.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\CarePlan;

class CarePlanMapper
{
    /**
     * Map a CarePlan model to a FHIR R4 CarePlan resource.
     * Eager-load carePlanGoals before calling if you want activity details.
     */
    public static function toFhir(CarePlan $carePlan): array
    {
        $effectiveDate = $carePlan->effective_date instanceof \Carbon\Carbon
            ? $carePlan->effective_date->format('Y-m-d')
            : null;
        $reviewDueDate = $carePlan->review_due_date instanceof \Carbon\Carbon
            ? $carePlan->review_due_date->format('Y-m-d')
            : null;

        return [
            'resourceType' => 'CarePlan',
            'id'           => (string) $carePlan->id,
            'status'       => self::mapStatus($carePlan->status),
            'intent'       => 'plan',

            // ── Title ──────────────────────────────────────────────────────────
            'title'       => "PACE Care Plan v{$carePlan->version}",
            'description' => $carePlan->overall_goals_text,

            // ── Subject ────────────────────────────────────────────────────────
            'subject' => [
                'reference' => "Patient/{$carePlan->participant_id}",
            ],

            // ── Period ────────────────────────────────────────────────────────
            'period' => array_filter([
                'start' => $effectiveDate,
                'end'   => $reviewDueDate,
            ]),

            // ── Category ─────────────────────────────────────────────────────
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://snomed.info/sct',
                            'code'    => '38717003',
                            'display' => 'PACE Care Plan',
                        ],
                    ],
                ],
            ],

            // ── Activities / goals summary (one per domain if goals loaded) ─────
            'activity' => $carePlan->carePlanGoals
                ? $carePlan->carePlanGoals->map(fn ($goal) => [
                    'detail' => [
                        'description' => "[{$goal->domain}] {$goal->goal_text}",
                        'status'      => 'in-progress',
                    ],
                ])->values()->all()
                : [],
        ];
    }

    /** Map NostosEMR care plan status to FHIR CarePlan status. */
    private static function mapStatus(?string $status): string
    {
        return match ($status) {
            'draft'        => 'draft',
            'active'       => 'active',
            'under_review' => 'active',
            'archived'     => 'revoked',
            default        => 'unknown',
        };
    }
}
