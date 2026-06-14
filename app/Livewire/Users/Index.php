<?php

namespace App\Livewire\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Users'])]
class Index extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public ?int $role_id = null;

    public string $status = 'active';

    public string $password = '';

    public function openCreate(): void
    {
        Gate::authorize('user.manage');
        $this->reset(['editingId', 'name', 'email', 'role_id', 'status', 'password']);
        $this->status = 'active';
        $this->role_id = Role::where('slug', 'employee')->value('id');
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        Gate::authorize('user.manage');
        $user = User::findOrFail($id);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role_id = $user->role_id;
        $this->status = $user->status;
        $this->password = '';
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize('user.manage');

        $data = $this->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:active,suspended',
            'password' => $this->editingId ? 'nullable|min:8' : 'required|min:8',
        ]);

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
            'status' => $data['status'],
        ];

        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        if ($this->editingId) {
            User::whereKey($this->editingId)->update($payload);
            session()->flash('status', 'User updated.');
        } else {
            User::create($payload);
            session()->flash('status', 'User created.');
        }

        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        Gate::authorize('user.manage');

        if ($id === auth()->id()) {
            session()->flash('status', 'You cannot delete your own account.');

            return;
        }

        User::whereKey($id)->delete();
        session()->flash('status', 'User deleted.');
    }

    public function render()
    {
        return view('livewire.users.index', [
            'users' => User::with('role')->orderBy('name')->get(),
            'roles' => Role::orderBy('name')->get(),
        ]);
    }
}
