<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'shared_audit_logs';

    // Append-only — disable update/delete at model level too
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'resource_type',
        'resource_id',
        'description',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // ─── Prevent accidental mutations ─────────────────────────────────────────

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new ImmutableRecordException('AuditLog');
        }

        return parent::save($options);
    }

    public function delete(): bool|null
    {
        throw new ImmutableRecordException('AuditLog');
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ─── Factory method ───────────────────────────────────────────────────────

    public static function record(
        string $action,
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $description = null,
        array $oldValues = null,
        array $newValues = null,
    ): static {
        return static::create([
            'tenant_id'     => $tenantId,
            'user_id'       => $userId,
            'action'        => $action,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'description'   => $description,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'old_values'    => $oldValues,
            'new_values'    => $newValues,
        ]);
    }
}
