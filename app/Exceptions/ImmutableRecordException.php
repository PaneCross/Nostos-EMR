<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to mutate an append-only record (e.g. AuditLog).
 * These records are immutable by design for HIPAA audit-trail integrity.
 */
class ImmutableRecordException extends RuntimeException
{
    public function __construct(string $model = 'Record')
    {
        parent::__construct("{$model} is immutable and cannot be updated or deleted.");
    }
}
