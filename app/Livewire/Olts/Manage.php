<?php

namespace App\Livewire\Olts;

use App\Models\Olt;
use App\Services\Olt\VendorDriverManager;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Manage OLT'])]
class Manage extends Component
{
    public ?Olt $olt = null;

    // Form fields
    public string $name = '';

    public string $ip_address = '';

    public string $vendor = 'huawei';

    public string $model = '';

    public string $location = '';

    public string $snmp_version = 'v2c';

    public int $snmp_port = 161;

    public string $snmp_community = '';

    public string $snmp_sec_name = '';

    public string $snmp_auth_protocol = 'SHA';

    public string $snmp_auth_password = '';

    public string $snmp_priv_protocol = 'AES';

    public string $snmp_priv_password = '';

    public string $ssh_username = '';

    public int $ssh_port = 22;

    public string $ssh_password = '';

    public string $status = 'active';

    public bool $live_fetch = true;

    public bool $is_simulated = false;

    public ?int $sync_interval = 15;

    public function mount(?Olt $olt = null): void
    {
        if ($olt && $olt->exists) {
            Gate::authorize('olt.update');
            $this->olt = $olt;

            $this->name = $olt->name;
            $this->ip_address = $olt->ip_address;
            $this->vendor = $olt->vendor;
            $this->model = (string) $olt->model;
            $this->location = (string) $olt->location;
            $this->snmp_version = $olt->snmp_version->value;
            $this->snmp_port = (int) $olt->snmp_port;
            $this->snmp_sec_name = (string) $olt->snmp_sec_name;
            $this->snmp_auth_protocol = $olt->snmp_auth_protocol ?: 'SHA';
            $this->snmp_priv_protocol = $olt->snmp_priv_protocol ?: 'AES';
            $this->ssh_username = (string) $olt->ssh_username;
            $this->ssh_port = (int) $olt->ssh_port;
            $this->status = $olt->status->value;
            $this->live_fetch = (bool) $olt->live_fetch;
            $this->is_simulated = (bool) $olt->is_simulated;
            $this->sync_interval = $olt->sync_interval;
            // Decrypted secret is shown so the operator can keep or change it.
            $this->snmp_community = (string) $olt->snmp_community;
        } else {
            Gate::authorize('olt.create');
        }
    }

    protected function rules(): array
    {
        $unique = 'unique:olts,ip_address';
        if ($this->olt) {
            $unique .= ','.$this->olt->id;
        }

        return [
            'name' => 'required|string|max:120',
            'ip_address' => "required|ip|$unique",
            'vendor' => 'required|string',
            'model' => 'nullable|string|max:120',
            'location' => 'nullable|string|max:160',
            'snmp_version' => 'required|in:v1,v2c,v3',
            'snmp_port' => 'required|integer|min:1|max:65535',
            'snmp_community' => 'required_unless:snmp_version,v3|nullable|string',
            'snmp_sec_name' => 'required_if:snmp_version,v3|nullable|string',
            'ssh_username' => 'nullable|string|max:64',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'status' => 'required|in:active,inactive,maintenance',
            'sync_interval' => 'nullable|integer|min:1|max:1440',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        $payload = array_merge($data, [
            'live_fetch' => $this->live_fetch,
            'is_simulated' => $this->is_simulated,
            'snmp_sec_name' => $this->snmp_sec_name ?: null,
            'snmp_auth_protocol' => $this->snmp_auth_protocol,
            'snmp_priv_protocol' => $this->snmp_priv_protocol,
            'ssh_username' => $this->ssh_username ?: null,
        ]);

        // Only overwrite secrets when a new value was entered.
        if ($this->snmp_community !== '') {
            $payload['snmp_community'] = $this->snmp_community;
        }
        if ($this->snmp_auth_password !== '') {
            $payload['snmp_auth_password'] = $this->snmp_auth_password;
        }
        if ($this->snmp_priv_password !== '') {
            $payload['snmp_priv_password'] = $this->snmp_priv_password;
        }
        if ($this->ssh_password !== '') {
            $payload['ssh_password'] = $this->ssh_password;
        }

        if ($this->olt) {
            $this->olt->update($payload);
            session()->flash('status', 'OLT updated.');
        } else {
            $payload['created_by'] = auth()->id();
            $this->olt = Olt::create($payload);
            session()->flash('status', 'OLT created. Trigger a sync to pull data.');
        }

        return $this->redirect(route('olts.show', $this->olt), navigate: true);
    }

    public function render()
    {
        return view('livewire.olts.manage', [
            'vendors' => app(VendorDriverManager::class)->availableVendors(),
        ]);
    }
}
