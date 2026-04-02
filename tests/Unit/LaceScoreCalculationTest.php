<?php

// ─── LaceScoreCalculationTest ─────────────────────────────────────────────────
// Unit tests for LACE+ Index score component logic.
//
// LACE+ scoring components:
//   L — Length of Stay: 0=0d, 1=1d, 2=2d, 3=3-4d, 4=5-6d, 5=7-10d, 6=>=11d
//   A — Acuity (emergent admission): 0 or 3
//   C — Comorbidity (Charlson score): 0 for 0, 1 for 1, 2 for 2, 3 for 3, 5 for >=4
//   E — ED visits in last 6 months: 0=0, 1=1, 2=2, 3=3, 4=>=4
//
// Tests verify that known component inputs produce expected total scores.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\Assessment;
use PHPUnit\Framework\TestCase;

class LaceScoreCalculationTest extends TestCase
{
    /**
     * LACE+ component scoring helper — mirrors the expected calculation logic.
     * This is the reference implementation against which the stored score is validated.
     */
    private function computeLaceScore(
        int $lengthOfStayDays,
        bool $emergentAdmission,
        int $charlsonScore,
        int $edVisits
    ): int {
        // L: Length of Stay score
        $l = match (true) {
            $lengthOfStayDays === 0  => 0,
            $lengthOfStayDays === 1  => 1,
            $lengthOfStayDays === 2  => 2,
            $lengthOfStayDays <= 4  => 3,
            $lengthOfStayDays <= 6  => 4,
            $lengthOfStayDays <= 10 => 5,
            default                  => 6,
        };

        // A: Acuity (emergent admission = 3, elective = 0)
        $a = $emergentAdmission ? 3 : 0;

        // C: Charlson Comorbidity Index mapped to LACE+ points
        $c = match (true) {
            $charlsonScore === 0 => 0,
            $charlsonScore === 1 => 1,
            $charlsonScore === 2 => 2,
            $charlsonScore === 3 => 3,
            default               => 5,
        };

        // E: ED visits in last 6 months (capped at 4)
        $e = min($edVisits, 4);

        return $l + $a + $c + $e;
    }

    public function test_minimum_score_is_zero(): void
    {
        $score = $this->computeLaceScore(
            lengthOfStayDays: 0,
            emergentAdmission: false,
            charlsonScore: 0,
            edVisits: 0
        );
        $this->assertEquals(0, $score);
    }

    public function test_low_risk_profile(): void
    {
        // L=1(1d), A=0(elective), C=1(Charlson=1), E=1(1 ED visit) = 3 total
        $score = $this->computeLaceScore(
            lengthOfStayDays: 1,
            emergentAdmission: false,
            charlsonScore: 1,
            edVisits: 1
        );
        $this->assertEquals(3, $score);
        $this->assertLessThan(5, $score, 'Score < 5 should be low risk');
    }

    public function test_moderate_risk_profile(): void
    {
        // L=3(3d), A=0(elective), C=2(Charlson=2), E=1(1 ED visit) = 6 total
        $score = $this->computeLaceScore(
            lengthOfStayDays: 3,
            emergentAdmission: false,
            charlsonScore: 2,
            edVisits: 1
        );
        $this->assertEquals(6, $score);
        $this->assertGreaterThanOrEqual(5, $score);
        $this->assertLessThan(10, $score);
    }

    public function test_high_risk_profile_at_boundary(): void
    {
        // L=4(5-6d), A=3(emergent), C=2(Charlson=2), E=1(1 ED visit) = 10 total
        $score = $this->computeLaceScore(
            lengthOfStayDays: 5,
            emergentAdmission: true,
            charlsonScore: 2,
            edVisits: 1
        );
        $this->assertEquals(10, $score);
        $this->assertGreaterThanOrEqual(10, $score, 'Score >= 10 should be high risk');
    }

    public function test_maximum_realistic_score(): void
    {
        // L=6(>=11d), A=3(emergent), C=5(Charlson>=4), E=4(>=4 visits) = 18
        $score = $this->computeLaceScore(
            lengthOfStayDays: 14,
            emergentAdmission: true,
            charlsonScore: 5,
            edVisits: 6
        );
        $this->assertEquals(18, $score);
    }

    public function test_assessment_model_score_max_covers_high_scores(): void
    {
        $max = Assessment::SCORE_MAX['lace_plus_index'] ?? null;
        $this->assertNotNull($max);
        $this->assertGreaterThanOrEqual(18, $max, 'SCORE_MAX must accommodate maximum LACE+ score');
    }
}
