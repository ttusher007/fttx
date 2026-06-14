<?php

use App\Livewire\ApiClients;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Olts;
use App\Livewire\Onus;
use App\Livewire\Roles;
use App\Livewire\SyncLogs;
use App\Livewire\Users;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/login', Login::class)->middleware('guest')->name('login');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');

    Route::get('/dashboard', Dashboard::class)
        ->middleware('permission:dashboard.view')->name('dashboard');

    // OLTs
    Route::middleware('permission:olt.view')->group(function () {
        Route::get('/olts', Olts\Index::class)->name('olts.index');
        Route::get('/olts/{olt}', Olts\Show::class)->name('olts.show');
    });
    Route::get('/olts-create', Olts\Manage::class)
        ->middleware('permission:olt.create')->name('olts.create');
    Route::get('/olts/{olt}/edit', Olts\Manage::class)
        ->middleware('permission:olt.update')->name('olts.edit');

    // ONUs
    Route::get('/onus', Onus\Index::class)
        ->middleware('permission:onu.view')->name('onus.index');

    // Sync logs
    Route::get('/logs', SyncLogs\Index::class)
        ->middleware('permission:log.view')->name('logs.index');

    // API clients
    Route::get('/api-clients', ApiClients\Index::class)
        ->middleware('permission:api.view')->name('api-clients.index');

    // Users
    Route::get('/users', Users\Index::class)
        ->middleware('permission:user.view')->name('users.index');

    // Roles
    Route::get('/roles', Roles\Index::class)
        ->middleware('permission:role.manage')->name('roles.index');
});
