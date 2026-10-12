<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings' Advanced card: knobs a developer flips while testing. They live in the core settings
 * table so they survive a restart, and the notification email switch falls back to the .env-backed
 * product config when no row exists, so an installation without a stored choice obeys its .env.
 */
final class PortalAdvancedSettings
{
    // The stored switch for whether mapped notices also go by email.
    public const NOTIFICATION_EMAILS = 'notifications.email_emails';

    public function __construct(private readonly PortalAudit $audit) {}

    // The stored choice, else the config default; '1'/'true' are on and '0'/'false' are off.
    public static function notificationEmails(): bool
    {
        $stored = DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', self::NOTIFICATION_EMAILS)
            ->value('setting_value');
        if ($stored === null) {
            return (bool) config('products.catalog.portal.notification_emails', true);
        }

        return in_array(strtolower(trim((string) $stored)), ['1', 'true'], true);
    }

    public function setNotificationEmails(Employee $actor, Request $request, bool $on): void
    {
        DB::connection('core')->table('settings')->updateOrInsert(
            ['product' => 'portal', 'setting_key' => self::NOTIFICATION_EMAILS],
            ['setting_value' => $on ? '1' : '0'],
        );

        // A developer's act: named only to someone in Developer mode, and no id that points back.
        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            'settings.advanced',
            '{UserName|You} turned notification emails '.($on ? 'on' : 'off'),
            null,
            $request,
            ['notificationEmails' => $on],
            true,
        );
    }
}
