<?php

use App\Http\Controllers\Api\Portal\Manage\AccessController;
use App\Http\Controllers\Api\Portal\Manage\BudgetAccountsController;
use App\Http\Controllers\Api\Portal\Manage\BudgetEnvelopesController;
use App\Http\Controllers\Api\Portal\Manage\FlaggedMembersController;
use App\Http\Controllers\Api\Portal\Manage\ProjectsController;
use App\Http\Controllers\Api\Portal\Manage\ProjectSettingsController;
use App\Http\Controllers\Api\Portal\Manage\ReportsController;
use App\Http\Controllers\Api\Portal\Manage\RequestsController;
use App\Http\Controllers\Api\Portal\Manage\UsersController;
use App\Http\Middleware\AuthenticatePortalJwt;
use App\Support\Portal\PortalFlaggedMembers;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // Manage / Projects follows Project Manager; which departments it covers is decided in PortalManageProjects.
    Route::middleware(['portal.rule:projects.manager', 'throttle:portal-projects'])
        ->get('manage/projects', [ProjectsController::class, 'index']);
    Route::middleware(['portal.rule:projects.manager', 'throttle:portal-projects-write'])
        ->patch('manage/projects/{id}', [ProjectsController::class, 'update'])
        ->whereNumber('id');
    // Manage / Project Settings is a work in progress, so Developer mode only for now: its rule is
    // held only while a developer is in Developer mode.
    Route::middleware(['portal.rule:projects.settings', 'throttle:portal-projects'])
        ->get('manage/project-settings', [ProjectSettingsController::class, 'index']);
    Route::middleware(['portal.rule:projects.settings', 'throttle:portal-projects-write'])->group(function (): void {
        Route::post('manage/project-settings/scopes', [ProjectSettingsController::class, 'storeScope']);
        Route::patch('manage/project-settings/scopes/{id}', [ProjectSettingsController::class, 'updateScope'])
            ->whereNumber('id');
        Route::delete('manage/project-settings/scopes/{id}', [ProjectSettingsController::class, 'destroyScope'])
            ->whereNumber('id');
        Route::post('manage/project-settings/job-types', [ProjectSettingsController::class, 'storeJobType']);
        Route::patch('manage/project-settings/job-types/{id}', [ProjectSettingsController::class, 'updateJobType'])
            ->whereNumber('id');
        Route::delete('manage/project-settings/job-types/{id}', [ProjectSettingsController::class, 'destroyJobType'])
            ->whereNumber('id');
        Route::post('manage/project-settings/departments', [ProjectSettingsController::class, 'storeDepartment']);
        Route::delete('manage/project-settings/departments/{id}', [ProjectSettingsController::class, 'destroyDepartment'])
            ->whereNumber('id');
        Route::post('manage/project-settings/clients', [ProjectSettingsController::class, 'storeClient']);
        Route::patch('manage/project-settings/clients/{id}', [ProjectSettingsController::class, 'updateClient'])
            ->whereNumber('id');
        Route::delete('manage/project-settings/clients/{id}', [ProjectSettingsController::class, 'destroyClient'])
            ->whereNumber('id');
    });
    // The roster and profiles follow Users and its reach; the private block inside a profile follows Show employee information.
    Route::middleware(['portal.rule:manage.users', 'throttle:portal-manage-users'])
        ->get('manage/users', [UsersController::class, 'index']);
    Route::middleware(['portal.rule:manage.users', 'throttle:portal-manage-users'])
        ->get('manage/users/{id}', [UsersController::class, 'show'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.users.role', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/role', [UsersController::class, 'updateRole'])
        ->whereNumber('id');
    // Add users: a new employee in the portal, before Airtable has them. Change departments: the
    // department the portal files someone under, which their reaches and leading follow.
    Route::middleware(['portal.rule:manage.users.add', 'throttle:portal-manage-users'])
        ->get('manage/users/new', [UsersController::class, 'newUser']);
    Route::middleware(['portal.rule:manage.users.add', 'throttle:portal-manage-users-write'])
        ->post('manage/users', [UsersController::class, 'store']);
    Route::middleware(['portal.rule:manage.users.department', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/department', [UsersController::class, 'updateDepartment'])
        ->whereNumber('id');
    // Edit profiles: the portal's own copy of an employee's signature details (Airtable is read only).
    Route::middleware(['portal.rule:manage.users.profile', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/profile', [UsersController::class, 'updateProfile'])
        ->whereNumber('id');
    // Each kind on the queue and its Approve or reject follow their own rule inside PortalManageRequests.
    Route::middleware(['portal.rule:manage.requests', 'throttle:portal-manage-requests'])
        ->get('manage/requests', [RequestsController::class, 'index']);
    // The envelope picker an approval charges: whoever may approve a reimbursement claim reads it.
    Route::middleware(['portal.rule:manage.requests.reimbursement.decide', 'throttle:portal-manage-requests'])
        ->get('manage/budget-envelopes', [BudgetEnvelopesController::class, 'index']);
    // The ledger and the budget codes feed all three Finance pages, so any of their rules reads them.
    Route::middleware(['portal.rule:finance.overview|finance.transactions|finance.envelopes', 'throttle:portal-manage-requests'])
        ->get('manage/budget-transactions', [BudgetEnvelopesController::class, 'transactions']);
    Route::middleware(['portal.rule:finance.overview|finance.transactions|finance.envelopes', 'throttle:portal-manage-requests'])
        ->get('manage/budget-codes', [BudgetEnvelopesController::class, 'codes']);
    // Finance / Envelopes adds to the tracker: adding and editing codes and envelopes is one power,
    // recording money received another, since it moves balances.
    Route::middleware(['portal.rule:finance.envelopes.edit', 'throttle:portal-manage-budget-write'])->group(function (): void {
        Route::post('manage/budget-codes', [BudgetEnvelopesController::class, 'storeCode']);
        Route::post('manage/budget-envelopes', [BudgetEnvelopesController::class, 'storeEnvelope']);
        Route::patch('manage/budget-codes/{id}', [BudgetEnvelopesController::class, 'updateCode'])
            ->whereNumber('id');
        Route::patch('manage/budget-envelopes/{id}', [BudgetEnvelopesController::class, 'updateEnvelope'])
            ->whereNumber('id');
    });
    Route::middleware(['portal.rule:finance.envelopes.funds', 'throttle:portal-manage-budget-write'])
        ->post('manage/budget-envelopes/{id}/funds', [BudgetEnvelopesController::class, 'storeFunds'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:finance.envelopes.transfer', 'throttle:portal-manage-budget-write'])
        ->post('manage/budget-envelopes/transfers', [BudgetEnvelopesController::class, 'storeTransfer']);
    Route::middleware(['portal.rule:finance.transactions.add', 'throttle:portal-manage-budget-write'])->group(function (): void {
        Route::post('manage/budget-transactions', [BudgetEnvelopesController::class, 'storeTransaction']);
        // A receipt photo is uploaded first and named by the transaction it goes with.
        Route::post('manage/budget-transactions/receipts', [BudgetEnvelopesController::class, 'storeTransactionReceipt']);
    });
    // Finance / Accounts: reading them is one power; adding, moving and settling money between them another.
    Route::middleware(['portal.rule:finance.accounts', 'throttle:portal-manage-requests'])
        ->get('manage/budget-accounts', [BudgetAccountsController::class, 'index']);
    Route::middleware(['portal.rule:finance.accounts.add', 'throttle:portal-manage-budget-write'])
        ->post('manage/budget-accounts', [BudgetAccountsController::class, 'store']);
    Route::middleware(['portal.rule:finance.accounts.funds', 'throttle:portal-manage-budget-write'])->group(function (): void {
        Route::post('manage/budget-accounts/{id}/funds', [BudgetAccountsController::class, 'storeFunds'])
            ->whereNumber('id');
        Route::post('manage/budget-accounts/transfers', [BudgetAccountsController::class, 'storeTransfer']);
        Route::post('manage/budget-accounts/transfers/{id}/complete', [BudgetAccountsController::class, 'completeTransfer'])
            ->whereNumber('id');
        Route::post('manage/budget-accounts/transfers/{id}/cancel', [BudgetAccountsController::class, 'cancelTransfer'])
            ->whereNumber('id');
    });
    Route::middleware(['portal.rule:manage.requests', 'throttle:portal-manage-requests-write'])
        ->patch('manage/requests', [RequestsController::class, 'update']);
    // Flagged users: whoever may approve or reject flagged reports puts people on review and takes them off.
    Route::middleware(['portal.rule:'.PortalFlaggedMembers::RULE, 'throttle:portal-manage-requests'])
        ->get('manage/flagged-members', [FlaggedMembersController::class, 'index']);
    Route::middleware(['portal.rule:'.PortalFlaggedMembers::RULE, 'throttle:portal-manage-requests-write'])->group(function (): void {
        Route::post('manage/flagged-members', [FlaggedMembersController::class, 'store']);
        Route::delete('manage/flagged-members/{id}', [FlaggedMembersController::class, 'destroy'])
            ->whereNumber('id');
    });
    Route::middleware(['portal.rule:manage.requests.reimbursement.payout', 'throttle:portal-manage-requests-write'])
        ->post('manage/requests/reimbursement/{id}/payout-receipt', [RequestsController::class, 'storePayoutReceipt'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.reports', 'throttle:portal-manage-reports'])
        ->get('manage/reports', [ReportsController::class, 'index']);
    Route::middleware(['portal.rule:manage.users.access', 'throttle:portal-manage-access'])
        ->get('manage/access/{id}', [AccessController::class, 'show'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.users.access', 'throttle:portal-manage-access-write'])
        ->patch('manage/access/{id}', [AccessController::class, 'update'])
        ->whereNumber('id');
});
