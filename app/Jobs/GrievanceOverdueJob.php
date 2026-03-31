<?php

// ─── GrievanceOverdueJob ──────────────────────────────────────────────────────
// Daily job (8:00 AM) that checks all tenants for overdue grievances and
// creates alerts for QA staff.
//
// CMS §460.120 timelines:
//   - Urgent: must be resolved within 72 hours
//   - Standard: must be resolved within 30 days
//
// For each overdue grievance, the job calls GrievanceService::checkOverdue()
// which creates critical/warning alerts targeting qa_compliance + it_admin.
// Standard overdue grievances are escalated to 'urgent' priority automatically.
//
// Queue: 'compliance' (same queue as DocumentationComplianceJob)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\GrievanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GrievanceOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(GrievanceService $service): void
    {
        $tenants = Tenant::where('is_active', true)->get();

        foreach ($tenants as $tenant) {
            try {
                $counts = $service->checkOverdue($tenant->id);

                if ($counts['urgent'] > 0 || $counts['standard'] > 0) {
                    Log::info("GrievanceOverdueJob: tenant #{$tenant->id} — "
                        . "{$counts['urgent']} urgent, {$counts['standard']} standard overdue alerts created.");
                }
            } catch (\Throwable $e) {
                // Log and continue — don't abort entire batch for one tenant
                Log::error("GrievanceOverdueJob: failed for tenant #{$tenant->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
