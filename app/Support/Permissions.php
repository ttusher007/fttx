<?php

namespace App\Support;

/**
 * Central registry of every permission in the system, plus the default
 * permission set for each seeded role. Used by the seeder, the authorization
 * Gate, and the role-management UI so they never drift apart.
 */
class Permissions
{
    /**
     * slug => [label, group]
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function definitions(): array
    {
        return [
            // Dashboard
            'dashboard.view' => ['View dashboard', 'Dashboard'],

            // OLT management
            'olt.view' => ['View OLTs', 'OLT'],
            'olt.create' => ['Create OLTs', 'OLT'],
            'olt.update' => ['Edit OLTs', 'OLT'],
            'olt.delete' => ['Delete OLTs', 'OLT'],
            'olt.sync' => ['Trigger OLT sync', 'OLT'],

            // ONU
            'onu.view' => ['View ONUs', 'ONU'],
            'onu.sync' => ['Trigger ONU sync', 'ONU'],

            // API clients
            'api.view' => ['View API keys', 'API'],
            'api.manage' => ['Create / revoke API keys', 'API'],

            // Users & roles
            'user.view' => ['View users', 'Administration'],
            'user.manage' => ['Create / edit users', 'Administration'],
            'role.manage' => ['Manage roles & permissions', 'Administration'],

            // Logs
            'log.view' => ['View sync logs', 'Monitoring'],
        ];
    }

    /**
     * @return array<string, string> slug => label
     */
    public static function all(): array
    {
        return collect(static::definitions())
            ->map(fn ($def) => $def[0])
            ->all();
    }

    /**
     * @return array<string, array{0:string,1:string}> grouped by group name
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (static::definitions() as $slug => [$label, $group]) {
            $groups[$group][$slug] = $label;
        }

        return $groups;
    }

    /**
     * Default permission slugs per seeded role. Super admin is implicitly all.
     *
     * @return array<string, array<int, string>>
     */
    public static function roleDefaults(): array
    {
        $all = array_keys(static::definitions());

        return [
            'super-admin' => $all, // also bypasses via Gate::before
            'noc-admin' => [
                'dashboard.view',
                'olt.view', 'olt.create', 'olt.update', 'olt.sync',
                'onu.view', 'onu.sync',
                'api.view', 'api.manage',
                'log.view',
            ],
            'employee' => [
                'dashboard.view',
                'olt.view',
                'onu.view',
                'log.view',
            ],
        ];
    }

    /**
     * @return array<string, string> slug => name for seeded roles
     */
    public static function roleNames(): array
    {
        return [
            'super-admin' => 'Super Admin',
            'noc-admin' => 'NOC Admin',
            'employee' => 'Employee',
        ];
    }
}
