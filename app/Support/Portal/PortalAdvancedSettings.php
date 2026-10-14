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

    // The stored switch for the login screen's ID-number box; off unless a developer turns it on,
    // so a deployed portal offers it only on purpose (2026-10-14, user).
    public const ID_LOGIN = 'login.id';

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

    // Off unless stored on: a fresh or deployed install never offers ID sign-in by accident.
    public static function idLogin(): bool
    {
        return self::storedOn(self::ID_LOGIN);
    }

    private static function storedOn(string $key): bool
    {
        $stored = DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', $key)
            ->value('setting_value');

        return $stored !== null && in_array(strtolower(trim((string) $stored)), ['1', 'true'], true);
    }

    public function setNotificationEmails(Employee $actor, Request $request, bool $on): void
    {
        $this->store($actor, $request, self::NOTIFICATION_EMAILS, $on, 'notification emails', ['notificationEmails' => $on]);
    }

    public function setIdLogin(Employee $actor, Request $request, bool $on): void
    {
        $this->store($actor, $request, self::ID_LOGIN, $on, 'ID number sign-in', ['idLogin' => $on]);
    }

    /**
     * @param  array<string, bool>  $payload
     */
    private function store(Employee $actor, Request $request, string $key, bool $on, string $label, array $payload): void
    {
        DB::connection('core')->table('settings')->updateOrInsert(
            ['product' => 'portal', 'setting_key' => $key],
            ['setting_value' => $on ? '1' : '0'],
        );

        // A developer's act: named only to someone in Developer mode, and no id that points back.
        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            'settings.advanced',
            '{UserName|You} turned '.$label.' '.($on ? 'on' : 'off'),
            null,
            $request,
            $payload,
            true,
        );
    }
}
