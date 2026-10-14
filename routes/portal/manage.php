<?php

use App\Http\Controllers\Api\Portal\Manage\AccessController;
use App\Http\Controllers\Api\Portal\Manage\BudgetAccountsController;
use App\Http\Controllers\Api\Portal\Manage\BudgetEnvelopesController;
use App\Http\Controllers\Api\Portal\Manage\FlaggedMembersController;
use App\Http\Controllers\Api\Portal\Manage\OnboardingController;
use App\Http\Controllers\Api\Portal\Manage\ProfileChangesController;
use App\Http\Controllers\Api\Portal\Manage\ProjectsController;
use App\Http\Controllers\Api\Portal\Manage\ReferencesController;
use App\Http\Controllers\Api\Portal\Manage\ReportsController;
use App\Http\Controllers\Api\Portal\Manage\RequestsController;
use App\Http\Controllers\Api\Portal\Manage\SharedPagesController;
use App\Http\Controllers\Api\Portal\Manage\TeamMapController;
use App\Http\Controllers\Api\Portal\Manage\UsersController;
use App\Http\Middleware\AuthenticatePortalJwt;
use App\Support\Portal\PortalFlaggedMembers;
use App\Support\Portal\PortalTeamMap;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticatePortalJwt::class)->group(function (): void {
    // Manage / Projects follows Project Manager; which departments it covers is decided in PortalManageProjects.
    Route::middleware(['portal.rule:projects.manager', 'throttle:portal-projects'])
        ->get('manage/projects', [ProjectsController::class, 'index']);
    // One project's full detail, read-only, for the details page its row opens.
    Route::middleware(['portal.rule:projects.manager', 'throttle:portal-projects'])
        ->get('manage/projects/{id}', [ProjectsController::class, 'show'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:projects.manager', 'throttle:portal-projects-write'])
        ->patch('manage/projects/{id}', [ProjectsController::class, 'update'])
        ->whereNumber('id');
    // Manage / References: an administrator's power. Opening and reading follows the base rule;
    // adding, changing and removing the four tabs' rows follows Edit references.
    Route::middleware(['portal.rule:projects.references', 'throttle:portal-projects'])
        ->get('manage/references', [ReferencesController::class, 'index']);
    Route::middleware(['portal.rule:projects.references.edit', 'throttle:portal-projects-write'])->group(function (): void {
        Route::post('manage/references/scopes', [ReferencesController::class, 'storeScope']);
        Route::patch('manage/references/scopes/{id}', [ReferencesController::class, 'updateScope'])
            ->whereNumber('id');
        Route::delete('manage/references/scopes/{id}', [ReferencesController::class, 'destroyScope'])
            ->whereNumber('id');
        // A department's scope tree, by its WBS code (100, 100-01, 100-01-01, 100-01-01-01).
        Route::post('manage/references/tree-scopes', [ReferencesController::class, 'storeTreeScope']);
        Route::patch('manage/references/tree-scopes/{code}', [ReferencesController::class, 'updateTreeScope'])
            ->where('code', '[0-9-]+');
        Route::delete('manage/references/tree-scopes/{code}', [ReferencesController::class, 'destroyTreeScope'])
            ->where('code', '[0-9-]+');
        Route::post('manage/references/job-types', [ReferencesController::class, 'storeJobType']);
        Route::patch('manage/references/job-types/{id}', [ReferencesController::class, 'updateJobType'])
            ->whereNumber('id');
        Route::delete('manage/references/job-types/{id}', [ReferencesController::class, 'destroyJobType'])
            ->whereNumber('id');
        Route::post('manage/references/departments', [ReferencesController::class, 'storeDepartment']);
        Route::patch('manage/references/departments/{id}', [ReferencesController::class, 'updateDepartment'])
            ->whereNumber('id');
        Route::delete('manage/references/departments/{id}', [ReferencesController::class, 'destroyDepartment'])
            ->whereNumber('id');
        Route::post('manage/references/clients', [ReferencesController::class, 'storeClient']);
        Route::patch('manage/references/clients/{id}', [ReferencesController::class, 'updateClient'])
            ->whereNumber('id');
        Route::delete('manage/references/clients/{id}', [ReferencesController::class, 'destroyClient'])
            ->whereNumber('id');
    });
    // Manage / Shared Pages: reading a share (link, QR, kept password) follows the base rule;
    // creating, changing and revoking follows Create and revoke; a new password follows New passwords.
    Route::middleware(['portal.rule:manage.shares', 'throttle:portal-projects'])
        ->get('manage/shared-pages', [SharedPagesController::class, 'index']);
    Route::middleware(['portal.rule:manage.shares', 'throttle:portal-projects'])
        ->get('manage/shared-pages/{id}/qr', [SharedPagesController::class, 'qr'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.shares.manage', 'throttle:portal-projects-write'])->group(function (): void {
        Route::post('manage/shared-pages', [SharedPagesController::class, 'store']);
        Route::patch('manage/shared-pages/{id}', [SharedPagesController::class, 'update'])
            ->whereNumber('id');
        Route::delete('manage/shared-pages/{id}', [SharedPagesController::class, 'destroy'])
            ->whereNumber('id');
    });
    Route::middleware(['portal.rule:manage.shares.password', 'throttle:portal-projects-write'])
        ->post('manage/shared-pages/{id}/regenerate', [SharedPagesController::class, 'regenerate'])
        ->whereNumber('id');
    // Manage / Onboarding: candidate registration links. Reading (list, one entry, QR, a document)
    // follows the base rule; creating, changing, revoking, marking sent and promoting follow
    // Create and revoke; a new password follows New passwords.
    Route::middleware(['portal.rule:manage.onboarding', 'throttle:portal-projects'])
        ->get('manage/registrations', [OnboardingController::class, 'index']);
    Route::middleware(['portal.rule:manage.onboarding', 'throttle:portal-projects'])
        ->get('manage/registrations/{id}', [OnboardingController::class, 'show'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.onboarding', 'throttle:portal-projects'])
        ->get('manage/registrations/{id}/qr', [OnboardingController::class, 'qr'])
        ->whereNumber('id');
    Route::middleware(['portal.rule:manage.onboarding', 'throttle:portal-projects'])
        ->get('manage/registrations/{id}/documents/{document}', [OnboardingController::class, 'document'])
        ->whereNumber('id')
        ->whereNumber('document');
    Route::middleware(['portal.rule:manage.onboarding.manage', 'throttle:portal-projects-write'])->group(function (): void {
        Route::post('manage/registrations', [OnboardingController::class, 'store']);
        Route::patch('manage/registrations/{id}', [OnboardingController::class, 'update'])
            ->whereNumber('id');
        Route::delete('manage/registrations/{id}', [OnboardingController::class, 'destroy'])
            ->whereNumber('id');
        Route::post('manage/registrations/{id}/sent', [OnboardingController::class, 'sent'])
            ->whereNumber('id');
    });
    // Promote candidates: the head admins' alone (2026-10-12); the account it makes is active at once.
    Route::middleware(['portal.rule:manage.onboarding.promote', 'throttle:portal-projects-write'])->group(function (): void {
        Route::get('manage/registrations/{id}/promotion', [OnboardingController::class, 'promotion'])
            ->whereNumber('id');
        Route::post('manage/registrations/{id}/promote', [OnboardingController::class, 'promote'])
            ->whereNumber('id');
    });
    Route::middleware(['portal.rule:manage.onboarding.password', 'throttle:portal-projects-write'])
        ->post('manage/registrations/{id}/password', [OnboardingController::class, 'regenerate'])
        ->whereNumber('id');
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
    // Approve profile changes: what people change about themselves on Settings, before it reaches their record (2026-10-14).
    Route::middleware(['portal.rule:manage.users.information.approve', 'throttle:portal-manage-users'])
        ->get('manage/users/changes', [ProfileChangesController::class, 'index']);
    Route::middleware(['portal.rule:manage.users.information.approve', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/changes/{id}', [ProfileChangesController::class, 'decide'])
        ->whereNumber('id');
    // Edit employee information: the private details of the people whose information someone sees (2026-10-12).
    Route::middleware(['portal.rule:manage.users.information.edit', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/information', [UsersController::class, 'updateInformation'])
        ->whereNumber('id');
    // An ID card prints private details, so it is made for the people whose information someone sees (2026-10-14).
    Route::middleware(['portal.rule:manage.users', 'throttle:portal-manage-users'])
        ->get('manage/users/{id}/id-card', [UsersController::class, 'idCard'])
        ->whereNumber('id');
    // Their documents follow the same power: a file into one of their slots, or one taken off (2026-10-13).
    Route::middleware(['portal.rule:manage.users.information.edit', 'throttle:portal-manage-users-write'])->group(function (): void {
        Route::post('manage/users/{id}/documents', [UsersController::class, 'storeDocument'])
            ->whereNumber('id');
        Route::delete('manage/users/{id}/documents/{documentId}', [UsersController::class, 'destroyDocument'])
            ->whereNumber(['id', 'documentId']);
    });
    // Activate and deactivate: an account Active or Inactive, which sign-in follows (2026-10-12).
    Route::middleware(['portal.rule:manage.users.status', 'throttle:portal-manage-users-write'])
        ->patch('manage/users/{id}/status', [UsersController::class, 'updateStatus'])
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
    Route::middleware(['portal.rule:finance.transactions.add', 'throttle:portal-manage-budget-write'])
        ->post('manage/budget-transactions', [BudgetEnvelopesController::class, 'storeTransaction']);
    // A receipt photo is uploaded first and named by the transaction it goes with, a new one or an edited one.
    Route::middleware(['portal.rule:finance.transactions.add|finance.transactions.edit', 'throttle:portal-manage-budget-write'])
        ->post('manage/budget-transactions/receipts', [BudgetEnvelopesController::class, 'storeTransactionReceipt']);
    // Edit transactions: a transaction's details, never its money (2026-10-13).
    Route::middleware(['portal.rule:finance.transactions.edit', 'throttle:portal-manage-budget-write'])
        ->patch('manage/budget-transactions/{id}', [BudgetEnvelopesController::class, 'updateTransaction'])
        ->whereNumber('id');
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
    // Manage / Map: where each person last saved something; an administrator's power.
    Route::middleware(['portal.rule:'.PortalTeamMap::RULE, 'throttle:portal-manage-requests'])
        ->get('manage/map', [TeamMapController::class, 'index']);
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
