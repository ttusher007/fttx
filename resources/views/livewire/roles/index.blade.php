<div class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">Roles &amp; Permissions</h2>
            <p class="text-sm text-slate-500">Control what each role can access.</p>
        </div>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New role
        </button>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($roles as $role)
            <div wire:key="r-{{ $role->id }}" class="card p-5">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-slate-800">{{ $role->name }}</h3>
                            @if ($role->is_system)<x-badge color="slate">system</x-badge>@endif
                        </div>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $role->description ?: '—' }}</p>
                    </div>
                </div>
                <div class="mt-4 flex gap-4 text-sm text-slate-500">
                    <span><span class="font-semibold text-slate-800">{{ $role->slug === 'super-admin' ? 'All' : $role->permissions_count }}</span> permissions</span>
                    <span><span class="font-semibold text-slate-800">{{ $role->users_count }}</span> users</span>
                </div>
                <div class="mt-4 flex gap-2 border-t border-slate-100 pt-4">
                    <button wire:click="openEdit({{ $role->id }})" class="btn-secondary flex-1 text-xs">Edit permissions</button>
                    @unless ($role->is_system)
                        <button wire:click="delete({{ $role->id }})" wire:confirm="Delete role {{ $role->name }}?" class="btn-secondary px-2.5 text-red-600 hover:bg-red-50">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7M4 7h16"/></svg>
                        </button>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 sm:items-center">
            <div class="card flex max-h-[90vh] w-full max-w-2xl flex-col p-6">
                <h3 class="text-base font-semibold text-slate-800">{{ $editingId ? 'Edit role' : 'New role' }}</h3>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label">Name</label>
                        <input wire:model="name" class="input">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Description</label>
                        <input wire:model="description" class="input">
                    </div>
                </div>

                <div class="mt-4 flex-1 overflow-y-auto">
                    <label class="label">Permissions</label>
                    <div class="space-y-4">
                        @foreach ($grouped as $group => $perms)
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $group }}</p>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @foreach ($perms as $slug => $label)
                                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600">
                                            <input type="checkbox" wire:model="selected" value="{{ $slug }}" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3 border-t border-slate-100 pt-4">
                    <button wire:click="$set('showModal', false)" class="btn-secondary">Cancel</button>
                    <button wire:click="save" class="btn-primary">Save role</button>
                </div>
            </div>
        </div>
    @endif
</div>
