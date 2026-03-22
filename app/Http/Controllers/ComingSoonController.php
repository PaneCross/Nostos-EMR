<?php

// ─── ComingSoonController ──────────────────────────────────────────────────────
// Renders the ComingSoon Inertia page for nav links that haven't been built yet.
//
// Two rendering modes (passed as $mode):
//   'transport' → ComingSoonBanner (amber "Nostos Transport Integration Pending")
//   'planned'   → PlannedFeatureBanner (indigo "Planned for Future Release")
//
// Remove individual routes (and their use of this controller) as each module is
// implemented and replaced with its real controller.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ComingSoonController extends Controller
{
    /**
     * Render the ComingSoon placeholder page.
     *
     * @param  string       $moduleLabel  Human-readable module name (e.g. "Clinical Orders")
     * @param  int          $phase        Planned development phase (for PlannedFeatureBanner label)
     * @param  string       $mode         'transport' = Nostos integration banner; 'planned' = roadmap banner
     * @param  string|null  $description  Optional one-sentence description (PlannedFeatureBanner only)
     */
    public function show(
        string $moduleLabel,
        ?int $phase = null,
        string $mode = 'planned',
        ?string $description = null
    ): Response {
        return Inertia::render('ComingSoon', [
            'module_label' => $moduleLabel,
            'phase'        => $phase,
            'mode'         => $mode,
            'description'  => $description,
        ]);
    }
}
