<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Developer mode: is_developer alone grants nothing. A developer uses the portal as their plain
 * role, bound by their own Access Rules, until they switch Developer mode on with their password;
 * then no rule applies to them. It is tied to the token's sid, so it lasts until they sign out.
 *
 * Every developer starts on the default password 123, which works once: switching on with it
 * requires a new password in the same request. Passwords are bcrypt hashes, never logged.
 */
final class PortalDeveloperMode
{
    public const DEFAULT_PASSWORD = '123';

    private const ATTRIBUTE = 'portalDeveloperMode';

    private const MIN_LENGTH = 8;

    public function __construct(private readonly PortalAudit $audit) {}

    // Read once per request: department scoping asks many times.
    public static function isOn(Employee $employee, Request $request): bool
    {
        if (! (bool) $employee->is_developer) {
            return false;
        }
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if (is_array($cached) && ($cached['id'] ?? null) === (int) $employee->getKey()) {
            return (bool) $cached['on'];
        }

        $session = $request->attributes->get('portalSessionId');
        $active = is_string($session) && $session !== ''
            && self::row($employee)?->active_session === $session;
        $request->attributes->set(self::ATTRIBUTE, ['id' => (int) $employee->getKey(), 'on' => $active]);

        return $active;
    }

    public function activate(Employee $developer, Request $request, string $password, ?string $newPassword): void
    {
        $session = $request->attributes->get('portalSessionId');
        if (! is_string($session) || $session === '') {
            abort(422, 'Sign in again to use Developer mode.');
        }

        $hash = self::row($developer)?->password_hash;
        $isDefault = ! is_string($hash) || $hash === '';
        $matches = $isDefault ? hash_equals(self::DEFAULT_PASSWORD, $password) : Hash::check($password, $hash);
        if (! $matches) {
            abort(422, 'That password is not right.');
        }

        $values = ['active_session' => $session];
        if ($isDefault) {
            if ($newPassword === null || $newPassword === '') {
                abort(428, 'Set a new password before using Developer mode.');
            }
            $values['password_hash'] = Hash::make($this->acceptable($newPassword));
        }
        $this->store($developer, $values);
        $request->attributes->set(self::ATTRIBUTE, ['id' => (int) $developer->getKey(), 'on' => true]);
        PortalCache::bump();

        $this->log($developer, $request, 'developer.mode', '{UserName|You} switched on Developer mode', ['on' => true, 'passwordSet' => $isDefault]);
    }

    public function deactivate(Employee $developer, Request $request): void
    {
        if (! self::isOn($developer, $request)) {
            return;
        }
        $this->store($developer, ['active_session' => null]);
        $request->attributes->set(self::ATTRIBUTE, ['id' => (int) $developer->getKey(), 'on' => false]);
        PortalCache::bump();

        $this->log($developer, $request, 'developer.mode', '{UserName|You} switched off Developer mode', ['on' => false]);
    }

    public function changePassword(Employee $developer, Request $request, string $current, string $new): void
    {
        if (! self::isOn($developer, $request)) {
            abort(403, 'Switch Developer mode on to change its password.');
        }
        $hash = self::row($developer)?->password_hash;
        if (! is_string($hash) || $hash === '' || ! Hash::check($current, $hash)) {
            abort(422, 'That password is not right.');
        }
        $this->store($developer, ['password_hash' => Hash::make($this->acceptable($new))]);

        $this->log($developer, $request, 'developer.password', '{UserName|You} changed the Developer mode password', []);
    }

    private function acceptable(string $password): string
    {
        if (mb_strlen($password) < self::MIN_LENGTH || $password === self::DEFAULT_PASSWORD) {
            abort(422, 'Use a new password of at least '.self::MIN_LENGTH.' characters.');
        }

        return $password;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function store(Employee $developer, array $values): void
    {
        DB::connection('portal')->table('developer_modes')->updateOrInsert(['employee_id' => $developer->getKey()], $values);
    }

    private static function row(Employee $employee): ?object
    {
        return DB::connection('portal')->table('developer_modes')->where('employee_id', $employee->getKey())->first();
    }

    /**
     * @param  array<string, bool>  $payload
     */
    private function log(Employee $developer, Request $request, string $resource, string $message, array $payload): void
    {
        $this->audit->record($developer, PortalLogAction::PATCH, $resource, $message, (string) $developer->getKey(), $request, $payload);
    }
}
