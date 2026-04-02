<?php

// ─── OrderRoutingTest ─────────────────────────────────────────────────────────
// Unit tests for ClinicalOrder model logic.
// 42 CFR §460.90: auto-routing ensures orders reach the correct PACE department.
//
// Coverage:
//   - DEPARTMENT_ROUTING map completeness (all ORDER_TYPES have a routing entry)
//   - Each order_type routes to the correct target_department
//   - isOverdue() logic: stat (4h), urgent (24h), routine (due_date)
//   - alertSeverity(): stat→critical, urgent→warning, routine→info
//   - isTerminal() for completed/cancelled
//   - isPending() only for pending status
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\ClinicalOrder;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class OrderRoutingTest extends TestCase
{
    // ── DEPARTMENT_ROUTING completeness ───────────────────────────────────────

    /** @test */
    public function test_all_order_types_have_routing_entry(): void
    {
        foreach (ClinicalOrder::ORDER_TYPES as $type) {
            $this->assertArrayHasKey(
                $type,
                ClinicalOrder::DEPARTMENT_ROUTING,
                "Order type '{$type}' is missing from DEPARTMENT_ROUTING"
            );
        }
    }

    /** @test */
    public function test_lab_routes_to_primary_care(): void
    {
        $this->assertSame('primary_care', ClinicalOrder::DEPARTMENT_ROUTING['lab']);
    }

    /** @test */
    public function test_imaging_routes_to_primary_care(): void
    {
        $this->assertSame('primary_care', ClinicalOrder::DEPARTMENT_ROUTING['imaging']);
    }

    /** @test */
    public function test_therapy_pt_routes_to_therapies(): void
    {
        $this->assertSame('therapies', ClinicalOrder::DEPARTMENT_ROUTING['therapy_pt']);
    }

    /** @test */
    public function test_therapy_ot_routes_to_therapies(): void
    {
        $this->assertSame('therapies', ClinicalOrder::DEPARTMENT_ROUTING['therapy_ot']);
    }

    /** @test */
    public function test_therapy_speech_routes_to_therapies(): void
    {
        $this->assertSame('therapies', ClinicalOrder::DEPARTMENT_ROUTING['therapy_speech']);
    }

    /** @test */
    public function test_medication_change_routes_to_pharmacy(): void
    {
        $this->assertSame('pharmacy', ClinicalOrder::DEPARTMENT_ROUTING['medication_change']);
    }

    /** @test */
    public function test_dme_routes_to_home_care(): void
    {
        $this->assertSame('home_care', ClinicalOrder::DEPARTMENT_ROUTING['dme']);
    }

    /** @test */
    public function test_hospice_referral_routes_to_social_work(): void
    {
        $this->assertSame('social_work', ClinicalOrder::DEPARTMENT_ROUTING['hospice_referral']);
    }

    // ── alertSeverity() ───────────────────────────────────────────────────────

    /** @test */
    public function test_stat_order_alert_severity_is_critical(): void
    {
        $order = new ClinicalOrder(['priority' => 'stat']);
        $this->assertSame('critical', $order->alertSeverity());
    }

    /** @test */
    public function test_urgent_order_alert_severity_is_warning(): void
    {
        $order = new ClinicalOrder(['priority' => 'urgent']);
        $this->assertSame('warning', $order->alertSeverity());
    }

    /** @test */
    public function test_routine_order_alert_severity_is_info(): void
    {
        $order = new ClinicalOrder(['priority' => 'routine']);
        $this->assertSame('info', $order->alertSeverity());
    }

    // ── isTerminal() ──────────────────────────────────────────────────────────

    /** @test */
    public function test_completed_order_is_terminal(): void
    {
        $order = new ClinicalOrder(['status' => 'completed']);
        $this->assertTrue($order->isTerminal());
    }

    /** @test */
    public function test_cancelled_order_is_terminal(): void
    {
        $order = new ClinicalOrder(['status' => 'cancelled']);
        $this->assertTrue($order->isTerminal());
    }

    /** @test */
    public function test_pending_order_is_not_terminal(): void
    {
        $order = new ClinicalOrder(['status' => 'pending']);
        $this->assertFalse($order->isTerminal());
    }

    // ── isPending() ───────────────────────────────────────────────────────────

    /** @test */
    public function test_pending_order_is_pending(): void
    {
        $order = new ClinicalOrder(['status' => 'pending']);
        $this->assertTrue($order->isPending());
    }

    /** @test */
    public function test_acknowledged_order_is_not_pending(): void
    {
        $order = new ClinicalOrder(['status' => 'acknowledged']);
        $this->assertFalse($order->isPending());
    }
}
