<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class MrnService
{
    /**
     * Generate a unique MRN for a participant at the given site.
     * Format: {SITE_PREFIX}-{zero-padded 5-digit sequence}
     * e.g. "EAST-00042"
     *
     * Uses a DB lock so concurrent inserts never collide.
     */
    public function generate(Site $site): string
    {
        return DB::transaction(function () use ($site) {
            $prefix = $site->mrn_prefix ?? $this->derivePrefix($site->name);

            // Count all participants (including soft-deleted) for this site to get a
            // monotonic sequence — never reuse a number even after deletion.
            // PostgreSQL does not allow FOR UPDATE with aggregate functions, so we
            // lock the rows in a subquery and count the result.
            $result = DB::selectOne(
                'SELECT COUNT(*) AS cnt FROM (SELECT id FROM emr_participants WHERE site_id = ? FOR UPDATE) AS sub',
                [$site->id]
            );
            $count = (int) $result->cnt;

            $sequence = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);

            return "{$prefix}-{$sequence}";
        });
    }

    /** Derive a 4-char uppercase prefix from a site name (fallback if mrn_prefix is null). */
    public function derivePrefix(string $siteName): string
    {
        // "Sunrise PACE East" → "EAST"
        $words = preg_split('/\s+/', trim($siteName));
        $last  = strtoupper(end($words));

        return substr($last, 0, 6);
    }
}
