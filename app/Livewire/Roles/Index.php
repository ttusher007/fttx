<?php

namespace App\Livewire\Roles;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Roles & Permissions'])]
class Index extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public array $selected = []; // permission slugs

    public function openCreate(): void
    {
        Gate::authorize('role.manage');
        $this->reset(['editingId', 'name', 'description', 'selected']);
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        Gate::authorize('role.manage');
        $role = Role::with('permissions')->findOrFail($id);
        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->selected = $role->permissions->pluck('slug')->all();
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize('role.manage');

        $data = $this->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:160',
            'selected' => 'array',
        ]);

        $role = $this->editingId
            ? Role::findOrFail($this->editingId)
            : new Role(['slug' => Str::slug($data['name']).'-'.Str::random(4)]);

        $role->name = $data['name'];
        $role->description = $data['description'];
        $role->save();

        // Super admin always retains all permissions.
        $slugs = $role->slug === 'super-admin'
            ? array_keys(Permissions::definitions())
            : $this->selected;

        $role->syncPermissionsBySlug($slugs);

        session()->flash('status', 'Role saved.');
        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        Gate::authorize('role.manage');
        $role = Role::findOrFail($id);

        if ($role->is_system) {
            session()->flash('status', 'System roles cannot be deleted.');

            return;
        }

        if ($role->users()->exists()) {
            session()->flash('status', 'Reassign users before deleting this role.');

            return;
        }

        $role->delete();
        session()->flash('status', 'Role deleted.');
    }

    public function render()
    {
        return view('livewire.roles.index', [
            'roles' => Role::withCount(['permissions', 'users'])->orderBy('name')->get(),
            'grouped' => Permissions::grouped(),
        ]);
    }
}
