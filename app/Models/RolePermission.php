<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class RolePermission extends Model
{
    protected $table = 'emr_role_permissions';

    protected $fillable = [
        'department',
        'role',
        'module',
        'can_view',
        'can_create',
        'can_edit',
        'can_delete',
        'can_export',
    ];

    protected $casts = [
        'can_view'   => 'boolean',
        'can_create' => 'boolean',
        'can_edit'   => 'boolean',
        'can_delete' => 'boolean',
        'can_export' => 'boolean',
    ];

    /**
     * Get all modules a department+role can view, cached for performance.
     *
     * @return \Illuminate\Support\Collection<string>
     */
    public static function visibleModulesFor(string $department, string $role): \Illuminate\Support\Collection
    {
        $cacheKey = "permissions.{$department}.{$role}.visible";

        return Cache::remember($cacheKey, 3600, function () use ($department, $role) {
            return static::where('department', $department)
                ->where('role', $role)
                ->where('can_view', true)
                ->pluck('module');
        });
    }

    /**
     * Check a specific permission for a department+role+module combo.
     */
    public static function check(
        string $department,
        string $role,
        string $module,
        string $ability = 'can_view'
    ): bool {
        $cacheKey = "permissions.{$department}.{$role}.{$module}.{$ability}";

        return Cache::remember($cacheKey, 3600, function () use ($department, $role, $module, $ability) {
            $perm = static::where('department', $department)
                ->where('role', $role)
                ->where('module', $module)
                ->first();

            return $perm ? (bool) $perm->{$ability} : false;
        });
    }

    public static function clearCache(): void
    {
        Cache::flush();
    }
}
