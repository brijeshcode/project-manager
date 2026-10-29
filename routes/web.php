<?php

use App\Livewire\Clients\Form as ClientForm;
use App\Livewire\Clients\Index as ClientIndex;
use App\Livewire\Portal\Dashboard as PortalDashboard;
use App\Livewire\Portal\ProjectView as PortalProjectView;
use App\Livewire\Projects\Form as ProjectForm;
use App\Livewire\Projects\Index as ProjectIndex;
use App\Livewire\Projects\Show as ProjectShow;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

// Client portal — gated to users with the 'client' role
Route::middleware(['auth', 'role:client'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', PortalDashboard::class)->name('dashboard');
    Route::get('/projects/{project}', PortalProjectView::class)->name('project');
});

// Client management — super_admin only
Route::middleware(['auth', 'role:super_admin'])->group(function () {
    Route::get('clients', ClientIndex::class)->name('clients.index');
    Route::get('clients/create', ClientForm::class)->name('clients.create');
    Route::get('clients/{client}/edit', ClientForm::class)->name('clients.edit');
});

Route::middleware(['auth'])->group(function () {
    Route::get('projects', ProjectIndex::class)->name('projects.index');
    Route::get('projects/create', ProjectForm::class)->name('projects.create');
    Route::get('projects/{project}', ProjectShow::class)->name('projects.show');
    Route::get('projects/{project}/edit', ProjectForm::class)->name('projects.edit');
});

require __DIR__.'/auth.php';
