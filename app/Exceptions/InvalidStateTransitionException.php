<?php

// ─── InvalidStateTransitionException ─────────────────────────────────────────
// Thrown by EnrollmentService::transition() when a caller attempts a state
// transition that is not permitted by the PACE enrollment state machine.
//
// Example: trying to move from 'enrolled' → 'intake_scheduled' (backwards),
// or skipping from 'new' → 'pending_enrollment' (jumping over required stages).
//
// HTTP mapping: 422 Unprocessable Entity (caught in ReferralController).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Exceptions;

use RuntimeException;

class InvalidStateTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct(
            "Invalid enrollment status transition: '{$fromStatus}' → '{$toStatus}'. " .
            'See EnrollmentService::VALID_TRANSITIONS for allowed paths.',
        );
    }
}
