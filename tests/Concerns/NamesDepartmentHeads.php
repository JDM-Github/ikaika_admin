<?php

namespace Tests\Concerns;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAdvancedSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Report-page submissions email only the head of the filer's department, as company_departments names
 * them (2026-10-14, user). These name one head and one plain admin in a department, so a test can show
 * which of the two the email reaches.
 */
trait NamesDepartmentHeads
{
    // The emails go out, as they would on a server with notification emails on.
    protected function turnNotificationEmailsOn(): void
    {
        Mail::fake();
        config(['products.catalog.portal.notification_emails' => true]);
        DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', PortalAdvancedSettings::NOTIFICATION_EMAILS)
            ->delete();
    }

    /**
     * One admin becomes the head of the member's department and another a plain admin in it, so the
     * head is the one the test names.
     *
     * @return array{0: Employee, 1: Employee} the head, then the plain admin
     */
    protected function nameDepartmentHead(Employee $member): array
    {
        $department = trim((string) $member->department);

        $admins = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('email')->where('email', '!=', '')
            ->whereKeyNot($member->getKey())
            ->orderBy('id')
            ->take(2)
            ->get()
            ->values();
        $this->assertCount(2, $admins);
        [$head, $admin] = [$admins[0], $admins[1]];
        $head->forceFill(['department' => $department, 'is_developer' => false])->save();
        $admin->forceFill(['department' => $department, 'is_developer' => false])->save();
        DB::connection('portal')->table('company_departments')->whereIn('head_employee_id', [$head->getKey(), $admin->getKey()])->update(['head_employee_id' => null]);
        $this->seatDepartmentHead($department, $head);

        return [$head, $admin];
    }

    // Makes the person the head of the department, adding the department when it is not one yet; null unseats its head.
    protected function seatDepartmentHead(string $department, ?Employee $head): void
    {
        $rows = DB::connection('portal')->table('company_departments');
        $existing = (clone $rows)->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($department))])->value('id');
        if ($existing === null) {
            $rows->insert(['name' => trim($department), 'head_employee_id' => $head?->getKey()]);

            return;
        }
        $rows->where('id', $existing)->update(['head_employee_id' => $head?->getKey()]);
    }

    protected function assertEmailedOnlyTheHead(Employee $head, Employee $admin, string $subject): void
    {
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $head->email) && $mail->subjectLine === $subject);
        Mail::assertNotSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $admin->email));
    }
}
