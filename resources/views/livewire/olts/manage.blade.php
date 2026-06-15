<div class="mx-auto max-w-3xl space-y-5">
    <div class="flex items-center gap-3">
        <a href="{{ route('olts.index') }}" wire:navigate class="rounded-lg p-2 text-slate-400 hover:bg-slate-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h2 class="text-lg font-semibold text-slate-800">{{ $olt ? 'Edit OLT' : 'Add OLT' }}</h2>
    </div>

    <form wire:submit="save" class="space-y-5">
        {{-- Identity --}}
        <div class="card p-5">
            <h3 class="mb-4 text-sm font-semibold text-slate-700">Device</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Name</label>
                    <input wire:model="name" class="input" placeholder="Dhaka-Core-01">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">IP Address</label>
                    <input wire:model="ip_address" class="input" placeholder="10.10.10.1">
                    @error('ip_address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Vendor</label>
                    <select wire:model="vendor" class="input">
                        @foreach ($vendors as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Model</label>
                    <input wire:model="model" class="input" placeholder="MA5800-X7">
                </div>
                <div>
                    <label class="label">PON Type</label>
                    <select wire:model="pon_type" class="input">
                        <option value="">Auto-detect on test</option>
                        <option value="gpon">GPON</option>
                        <option value="epon">EPON</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Leave on auto to let "Test connection" detect it. Choosing a value locks it.</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Location</label>
                    <input wire:model="location" class="input" placeholder="Dhaka, BD">
                </div>
            </div>
        </div>

        {{-- SNMP --}}
        <div class="card p-5" x-data="{ v: @entangle('snmp_version') }">
            <h3 class="mb-4 text-sm font-semibold text-slate-700">SNMP Credentials</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label">Version</label>
                    <select wire:model.live="snmp_version" class="input">
                        <option value="v1">v1</option>
                        <option value="v2c">v2c</option>
                        <option value="v3">v3</option>
                    </select>
                </div>
                <div>
                    <label class="label">Port</label>
                    <input wire:model="snmp_port" type="number" class="input">
                </div>
                <div x-show="v !== 'v3'">
                    <label class="label">Community</label>
                    <input wire:model="snmp_community" class="input" placeholder="public" type="password">
                    @error('snmp_community') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div x-show="v === 'v3'" x-cloak class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Security name</label>
                    <input wire:model="snmp_sec_name" class="input">
                    @error('snmp_sec_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div></div>
                <div>
                    <label class="label">Auth protocol</label>
                    <select wire:model="snmp_auth_protocol" class="input"><option>SHA</option><option>MD5</option></select>
                </div>
                <div>
                    <label class="label">Auth password</label>
                    <input wire:model="snmp_auth_password" type="password" class="input" placeholder="{{ $olt ? 'unchanged' : '' }}">
                </div>
                <div>
                    <label class="label">Priv protocol</label>
                    <select wire:model="snmp_priv_protocol" class="input"><option>AES</option><option>DES</option></select>
                </div>
                <div>
                    <label class="label">Priv password</label>
                    <input wire:model="snmp_priv_password" type="password" class="input" placeholder="{{ $olt ? 'unchanged' : '' }}">
                </div>
            </div>
        </div>

        {{-- SSH (optional) --}}
        <div class="card p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">SSH (optional fallback)</h3>
            <p class="mb-4 text-xs text-slate-400">Used only for vendor actions SNMP can't perform. Sync uses SNMP.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label">Username</label>
                    <input wire:model="ssh_username" class="input">
                </div>
                <div>
                    <label class="label">Port</label>
                    <input wire:model="ssh_port" type="number" class="input">
                </div>
                <div>
                    <label class="label">Password</label>
                    <input wire:model="ssh_password" type="password" class="input" placeholder="{{ $olt ? 'unchanged' : '' }}">
                </div>
            </div>
        </div>

        {{-- Operation --}}
        <div class="card p-5">
            <h3 class="mb-4 text-sm font-semibold text-slate-700">Operation</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label">Status</label>
                    <select wire:model="status" class="input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
                <div>
                    <label class="label">Sync interval (min)</label>
                    <input wire:model="sync_interval" type="number" class="input">
                </div>
            </div>
            <div class="mt-4 space-y-2">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input wire:model="live_fetch" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Include in continuous live sync
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input wire:model="is_simulated" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Simulated device (generate demo data — no real SNMP)
                </label>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('olts.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save OLT</button>
        </div>
    </form>
</div>
