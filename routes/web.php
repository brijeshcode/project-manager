<?php

use App\Livewire\Actions\Logout;
use App\Livewire\Clients\Form as ClientForm;
use App\Livewire\Clients\Index as ClientIndex;
use App\Livewire\Clients\Show as ClientShow;
use App\Livewire\Portal\Dashboard as PortalDashboard;
use App\Livewire\Portal\ProjectView as PortalProjectView;
use App\Livewire\Projects\Form as ProjectForm;
use App\Livewire\Projects\Index as ProjectIndex;
use App\Livewire\Projects\Show as ProjectShow;
use App\Livewire\Tasks\Board as TaskBoard;
use App\Livewire\Tasks\Form as TaskForm;
use App\Models\Task;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::post('logout', function (Logout $logout) {
    $logout();

    return redirect('/');
})->middleware('auth')->name('logout');

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
    Route::get('/invoices', \App\Livewire\Portal\Invoices::class)->name('invoices');
    Route::get('/payments', \App\Livewire\Portal\Payments::class)->name('payments');
});

// Client management — super_admin only
Route::middleware(['auth', 'role:super_admin'])->group(function () {
    Route::get('clients', ClientIndex::class)->name('clients.index');
    Route::get('clients/create', ClientForm::class)->name('clients.create');
    Route::get('clients/{client}/edit', ClientForm::class)->name('clients.edit');
    Route::get('clients/{client}', ClientShow::class)->name('clients.show');
});

// Task types and labels: super admin only (Gate manage-taxonomy)
Route::middleware(['auth', 'can:manage-system'])->get('settings/system', \App\Livewire\Settings\System::class)->name('settings.system');
Route::middleware(['auth', 'can:manage-taxonomy'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('task-types', \App\Livewire\Settings\TaskTypes::class)->name('task-types');
    Route::get('labels', \App\Livewire\Settings\Labels::class)->name('labels');
});

Route::get('billing/recurring', \App\Livewire\Billing\Recurring::class)
    ->middleware(['auth', 'can:manage-money'])->name('billing.recurring');
Route::middleware(['auth', 'can:manage-money'])->prefix('currencies')->name('currencies.')->group(function () {
    Route::get('/', \App\Livewire\Currencies\Index::class)->name('index');
    Route::get('create', \App\Livewire\Currencies\Form::class)->name('create');
    Route::get('{currency}/edit', \App\Livewire\Currencies\Form::class)->name('edit');
});

Route::get('settings', \App\Livewire\Settings\Index::class)
    ->middleware(['auth', 'can:manage-money'])->name('settings.index');
Route::get('billing/bank-accounts', \App\Livewire\Billing\BankAccounts::class)
    ->middleware(['auth', 'can:manage-money'])->name('billing.bank-accounts');

Route::get('projects/{project}/economics', \App\Livewire\Projects\Economics::class)
    ->middleware(['auth', 'can:manage-money'])->name('projects.economics');

Route::get('payments', \App\Livewire\Payments\Index::class)
    ->middleware(['auth', 'can:manage-money'])->name('payments.index');
Route::middleware(['auth', 'can:manage-money'])->prefix('payments')->name('payments.')->whereNumber('payment')->group(function () {
    Route::get('{payment}', \App\Livewire\Payments\Show::class)->name('show');
    Route::get('{payment}/edit', \App\Livewire\Payments\Edit::class)->name('edit');
});

Route::get('payment-documents/{document}/download', \App\Http\Controllers\PaymentDocumentDownloadController::class)
    ->whereNumber('document')->middleware(['auth', 'can:manage-money'])->name('payment-documents.download');

Route::middleware(['auth', 'can:manage-money'])->prefix('invoices')->name('invoices.')->group(function () {
    Route::get('/', \App\Livewire\Invoices\Index::class)->name('index');
    Route::get('create', \App\Livewire\Invoices\Create::class)->name('create');
    Route::get('{invoice}', \App\Livewire\Invoices\Show::class)->name('show');
    Route::get('{invoice}/edit', \App\Livewire\Invoices\Edit::class)->name('edit');
});

Route::middleware(['auth'])->group(function () {
    Route::get('invoices/{invoice}/pdf', \App\Http\Controllers\InvoicePdfController::class)->name('invoices.pdf');
    Route::get('projects', ProjectIndex::class)->name('projects.index');
    Route::get('projects/create', ProjectForm::class)->name('projects.create');
    Route::get('projects/{project}', ProjectShow::class)->name('projects.show');
    Route::get('projects/{project}/edit', ProjectForm::class)->name('projects.edit');
    Route::get('projects/{project}/modules/create', \App\Livewire\Modules\Create::class)->name('modules.create');
    Route::get('projects/{project}/modules/{module}', \App\Livewire\Modules\Show::class)->scopeBindings()->name('modules.show');
    Route::get('attachments/{attachment}/download', \App\Http\Controllers\AttachmentDownloadController::class)->whereNumber('attachment')->name('attachments.download');
    Route::get('projects/{project}/board', TaskBoard::class)->name('projects.board');
    Route::get('tasks/create', TaskForm::class)->name('tasks.create');
    Route::get('tasks/{task}/edit', TaskForm::class)->name('tasks.edit');
    Route::get('tasks/{task}', \App\Livewire\Tasks\Show::class)->whereNumber('task')->name('tasks.show');
    // short link by code: /t/NWS-12 -> the task page, 404 unless the user may see it
    Route::get('t/{code}', function (string $code) {
        abort_unless(preg_match('/^([A-Za-z0-9]{2,5})-(\d+)$/', $code, $m), 404);
        $task = Task::visibleTo(auth()->user())->where('number', (int) $m[2])
            ->whereHas('project', fn ($p) => $p->where('key', strtoupper($m[1])))->first();
        abort_unless($task && auth()->user()->can('view', $task->project), 404);

        return redirect()->route('tasks.show', $task);
    })->name('tasks.code');
    Route::get('subtasks/{subtask}',\App\Livewire\Subtasks\Show::class)->whereNumber('subtask')->name('subtasks.show');
});

require __DIR__.'/auth.php';
