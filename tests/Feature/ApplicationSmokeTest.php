<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Olt;
use App\Models\Role;
use App\Models\User;
use App\Services\Olt\OltSyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleSlug): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create([
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'status' => 'active',
        ]);
    }

    private function makeSyncedOlt(): Olt
    {
        $olt = Olt::create([
            'name' => 'Test-OLT',
            'ip_address' => '10.0.0.1',
            'vendor' => 'huawei',
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'status' => 'active',
            'is_simulated' => true,
            'live_fetch' => true,
        ]);

        app(OltSyncService::class)->sync($olt, 'manual');

        return $olt->fresh();
    }

    public function test_super_admin_can_load_every_page(): void
    {
        $admin = $this->makeUser('super-admin');
        $olt = $this->makeSyncedOlt();

        $pages = [
            route('dashboard'),
            route('olts.index'),
            route('olts.show', $olt),
            route('olts.create'),
            route('olts.edit', $olt),
            route('diagnostics.index', ['olt' => $olt->id]),
            route('onus.index'),
            route('logs.index'),
            route('api-clients.index'),
            route('users.index'),
            route('roles.index'),
        ];

        foreach ($pages as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_simulated_sync_populates_onus(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $olt = $this->makeSyncedOlt();

        $this->assertGreaterThan(0, $olt->onu_count);
        $this->assertGreaterThan(0, $olt->port_count);
        $this->assertDatabaseHas('sync_logs', ['olt_id' => $olt->id, 'status' => 'success']);
    }

    public function test_employee_cannot_access_user_admin(): void
    {
        $employee = $this->makeUser('employee');

        $this->actingAs($employee)->get(route('users.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('dashboard'))->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_api_lookup_returns_onu_by_serial(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $olt = $this->makeSyncedOlt();
        $serial = $olt->onus()->whereNotNull('serial_number')->value('serial_number');

        [$client, $secret] = ApiClient::issue('Test', ['onu.lookup']);

        $this->withHeaders([
            'X-Api-Key' => $client->key,
            'X-Api-Secret' => $secret,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/onu/lookup', ['serial_number' => $serial])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.serial_number', $serial);
    }

    public function test_api_rejects_invalid_secret(): void
    {
        [$client] = ApiClient::issue('Test', ['onu.lookup']);

        $this->withHeaders([
            'X-Api-Key' => $client->key,
            'X-Api-Secret' => 'nope',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/onu/lookup', ['serial_number' => 'X'])
            ->assertUnauthorized();
    }
}
