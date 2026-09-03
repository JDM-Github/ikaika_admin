<?php

use Illuminate\Support\Facades\Route;

/*
 * Portal channel routes. Folders match the playground / frontend sidebar:
 *   routes/portal/auth.php      Auth
 *   routes/portal/home.php      Home / Dashboard
 *   routes/portal/projects.php  View Projects
 *   routes/portal/manage.php    Manage / Users
 *   routes/portal/reports.php   Reports / Submitted
 *   routes/portal/requests.php  Requests / Overtime
 *   routes/portal/calendar.php        Calendar / Holidays
 *   routes/portal/administration.php  Administration / Recycle Bin
 */

Route::prefix('portal')->group(function (): void {
    require __DIR__.'/auth.php';
    require __DIR__.'/home.php';
    require __DIR__.'/projects.php';
    require __DIR__.'/manage.php';
    require __DIR__.'/reports.php';
    require __DIR__.'/requests.php';
    require __DIR__.'/calendar.php';
    require __DIR__.'/administration.php';
});
