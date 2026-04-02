<?php

// ─── OrganizationMapper ───────────────────────────────────────────────────────
// Maps NostosEMR Tenant and Site records to FHIR R4 Organization resources.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/organization.html
//
// Two resource shapes are produced:
//
//   fromTenant(Tenant) → Organization id='tenant-{id}'
//     Represents the PACE organization (legal entity). The CMS H-number
//     (cms_contract_id, e.g. "H1234") is stored as the PACE-system identifier.
//     This is the billing organization that submits 837P claims to CMS.
//
//   fromSite(Site) → Organization id='site-{id}'
//     Represents a physical PACE center (day center, clinic site). Includes
//     address fields and a partOf reference to the parent tenant Organization.
//
// ID scheme uses prefixes ("tenant-" / "site-") to prevent ID collision between
// tenant and site resources within the same FHIR server namespace.
//
// W4-9 — GAP-13: FHIR R4 Organization resource.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\Site;
use App\Models\Tenant;

class OrganizationMapper
{
    /**
     * Map a Tenant (PACE legal entity) to a FHIR R4 Organization resource.
     *
     * The H-number (CMS PACE contract ID) is used as the organization identifier.
     * Format: "H1234" stored in shared_tenants.cms_contract_id.
     */
    public static function fromTenant(Tenant $tenant): array
    {
        return [
            'resourceType' => 'Organization',
            'id'           => 'tenant-' . $tenant->id,

            // CMS H-number identifier (PACE contract ID)
            // Empty array when cms_contract_id not yet assigned (pre-go-live tenant)
            'identifier' => $tenant->cms_contract_id ? [
                [
                    'system' => 'https://www.cms.gov/pace',
                    'type'   => [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0203',
                                'code'    => 'XX',
                                'display' => 'Organization identifier',
                            ],
                        ],
                    ],
                    'value' => $tenant->cms_contract_id,
                ],
            ] : [],

            'active' => (bool) $tenant->is_active,

            'type' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/organization-type',
                            'code'    => 'prov',
                            'display' => 'Healthcare Provider',
                        ],
                    ],
                ],
            ],

            'name' => $tenant->name,
        ];
    }

    /**
     * Map a Site (physical PACE center) to a FHIR R4 Organization resource.
     *
     * Sites are child organizations of the tenant (partOf reference).
     * Address fields are optional — some sites (telehealth-only) may not have
     * a physical address.
     */
    public static function fromSite(Site $site): array
    {
        return [
            'resourceType' => 'Organization',
            'id'           => 'site-' . $site->id,

            // Sites use the tenant-level H-number for billing — no separate site identifier
            'identifier' => [],

            'active' => (bool) $site->is_active,

            'type' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/organization-type',
                            'code'    => 'prov',
                            'display' => 'Healthcare Provider',
                        ],
                    ],
                ],
            ],

            'name' => $site->name,

            // Physical address — omitted when not set (telehealth or virtual sites)
            'address' => $site->address ? [
                [
                    'use'        => 'work',
                    'line'       => [$site->address],
                    'city'       => $site->city,
                    'state'      => $site->state,
                    'postalCode' => $site->zip,
                    'country'    => 'US',
                ],
            ] : [],

            // Parent PACE organization reference
            'partOf' => ['reference' => "Organization/tenant-{$site->tenant_id}"],
        ];
    }
}
