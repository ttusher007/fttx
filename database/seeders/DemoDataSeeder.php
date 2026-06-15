<?php

namespace Database\Seeders;

use App\Models\ApiClient;
use App\Models\Olt;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'slug');

        // --- Users -----------------------------------------------------
        $users = [
            ['Super Admin', 'admin@fttx.test', 'super-admin'],
            ['NOC Admin', 'noc@fttx.test', 'noc-admin'],
            ['Employee', 'staff@fttx.test', 'employee'],
        ];

        foreach ($users as [$name, $email, $roleSlug]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'role_id' => $roles[$roleSlug] ?? null,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );
        }

        $admin = User::where('email', 'admin@fttx.test')->first();

        // --- Demo OLTs (simulated, so syncs work with no hardware) -----
        $demoOlts = [
            ['Dhaka-Core-01', '10.10.10.1', 'huawei', 'MA5800-X7', 'gpon'],
            ['Dhaka-Core-02', '10.10.10.2', 'huawei', 'MA5608T', 'gpon'],
            ['Gulshan-POP-01', '10.20.10.1', 'bdcom', 'P3310B', 'epon'],
            ['Banani-POP-01', '10.20.20.1', 'vsol', 'V1600G', 'gpon'],
            ['Uttara-POP-01', '10.30.10.1', 'bdcom', 'P3608B', 'epon'],
            ['Mirpur-POP-01', '10.40.10.1', 'vsol', 'V1600D', 'gpon'],
        ];

        foreach ($demoOlts as $i => [$name, $ip, $vendor, $model, $ponType]) {
            Olt::updateOrCreate(
                ['ip_address' => $ip, 'snmp_port' => 161],
                [
                    'name' => $name,
                    'vendor' => $vendor,
                    'model' => $model,
                    'pon_type' => $ponType,
                    'location' => 'Dhaka, BD',
                    'snmp_version' => 'v2c',
                    'snmp_community' => 'public',
                    'status' => 'active',
                    'live_fetch' => true,
                    'is_simulated' => true,
                    'sync_interval' => 15,
                    'created_by' => $admin?->id,
                ],
            );
        }

        // --- Demo API client -------------------------------------------
        if (! ApiClient::where('name', 'Demo Integration')->exists()) {
            [$client, $secret] = ApiClient::issue(
                name: 'Demo Integration',
                abilities: ['onu.lookup', 'sync.request'],
                rateLimit: 120,
                createdBy: $admin?->id,
            );

            $this->command?->warn('────────────────────────────────────────────');
            $this->command?->warn(' Demo API credentials (shown once):');
            $this->command?->warn("   Key:    {$client->key}");
            $this->command?->warn("   Secret: {$secret}");
            $this->command?->warn('────────────────────────────────────────────');
        }
    }
}
