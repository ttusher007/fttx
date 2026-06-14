<div class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">Users</h2>
            <p class="text-sm text-slate-500">Manage team members and their roles.</p>
        </div>
        @can('user.manage')
            <button wire:click="openCreate" class="btn-primary">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add user
            </button>
        @endcan
    </div>

    <div class="card overflow-hidden">
        <div class="divide-y divide-slate-100">
            @foreach ($users as $user)
                <div wire:key="u-{{ $user->id }}" class="flex items-center justify-between gap-3 p-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-800">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $user->email }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-badge color="indigo">{{ $user->role?->name ?? 'No role' }}</x-badge>
                        <x-badge :color="$user->status === 'active' ? 'emerald' : 'red'" class="hidden sm:inline-flex">{{ ucfirst($user->status) }}</x-badge>
                        @can('user.manage')
                            <button wire:click="openEdit({{ $user->id }})" class="btn-secondary text-xs">Edit</button>
                            @if ($user->id !== auth()->id())
                                <button wire:click="delete({{ $user->id }})" wire:confirm="Delete {{ $user->name }}?" class="btn-secondary px-2.5 text-red-600 hover:bg-red-50">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            @endif
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 sm:items-center">
            <div class="card w-full max-w-md p-6">
                <h3 class="text-base font-semibold text-slate-800">{{ $editingId ? 'Edit user' : 'Add user' }}</h3>
                <div class="mt-4 space-y-4">
                    <div>
                        <label class="label">Name</label>
                        <input wire:model="name" class="input">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Email</label>
                        <input wire:model="email" type="email" class="input">
                        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Role</label>
                            <select wire:model="role_id" class="input">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                            @error('role_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Status</label>
                            <select wire:model="status" class="input">
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="label">Password {{ $editingId ? '(leave blank to keep)' : '' }}</label>
                        <input wire:model="password" type="password" class="input">
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="$set('showModal', false)" class="btn-secondary">Cancel</button>
                    <button wire:click="save" class="btn-primary">Save</button>
                </div>
            </div>
        </div>
    @endif
</div>
