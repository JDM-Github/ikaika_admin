<?php

namespace Tests\Concerns;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAdvancedSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Report-page submissions email only the head of the filer's department, its Management-level admin
 * (2026-10-13, user). These name one head and one plain admin in a department, so a test can show
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
     * One admin becomes the head of the member's department and another a plain admin in it; any
     * other Management admin there steps down, so the head is the one the test names.
     *
     * @return array{0: Employee, 1: Employee} the head, then the plain admin
     */
    protected function nameDepartmentHead(Employee $member): array
    {
        $department = trim((string) $member->department);
        Employee::query()
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = ?", [strtolower($department)])
            ->whereRaw("LOWER(TRIM(COALESCE(role_level, ''))) = 'management'")
            ->update(['role_level' => null]);

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
        $head->forceFill(['department' => $department, 'role_level' => 'Management', 'is_developer' => false])->save();
        $admin->forceFill(['department' => $department, 'role_level' => null, 'is_developer' => false])->save();

        return [$head, $admin];
    }

    protected function assertEmailedOnlyTheHead(Employee $head, Employee $admin, string $subject): void
    {
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $head->email) && $mail->subjectLine === $subject);
        Mail::assertNotSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $admin->email));
    }
}
