<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Emails whoever holds Access alerts (administration.alerts) when a signed-in user hits a route they
 * cannot use. Admins and executives hold it by preset, so by default they get it; switching it off,
 * or a limit on it, takes someone off the list, and a gift puts them on it.
 *
 * Unauthenticated 401s are login noise and stay quiet. Mail is rate-limited per actor,
 * method, and path so a probe cannot become a mail bomb. The body never includes tokens. Each holder
 * gets their own letter (PortalLetters) through the mail queue, so the refused request answers
 * without a relay round trip.
 */
final class PortalAccessAlert
{
    public const CACHE_TTL_SECONDS = 900;

    public const SUBJECT = 'Blocked portal access attempt';

    private const ALERTS_RULE = 'administration.alerts';

    public function __construct(private readonly PortalTimezone $timezone) {}

    public function report(Request $request, string $reason): bool
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            return false;
        }

        Log::warning('portal.access.denied', [
            'employee_id' => $actor->getKey(),
            'method' => $request->method(),
            'path' => $request->path(),
            'reason' => $reason,
        ]);

        $key = 'portal:access-alert:'.$actor->getKey().':'.$request->method().':'.$request->path();
        if (! Cache::add($key, 1, self::CACHE_TTL_SECONDS)) {
            return false;
        }

        $recipients = $this->recipients($actor);
        if ($recipients === []) {
            return false;
        }

        $message = $this->message($actor, $request, $reason);
        foreach ($recipients as $email => $firstName) {
            PortalLetters::send(
                $email,
                PortalEmailMarkup::CATEGORY_WARNING,
                self::SUBJECT,
                PortalLetters::body($firstName, $message),
                [],
                'portal.access.alert.failed',
                ['employee_id' => (int) $actor->getKey()],
            );
        }

        return true;
    }

    // What was tried, by whom, and why it was refused; every value is the user's own text, so it is escaped.
    private function message(Employee $actor, Request $request, string $reason): string
    {
        $name = trim((string) $actor->first_name.' '.(string) $actor->last_name);
        $idNo = trim((string) $actor->id_no);
        $role = trim((string) $actor->role.' / '.(string) $actor->role_level, ' /');

        // The details sit in a panel of their own, apart from the sentences around them.
        return implode("\n", [
            'A signed-in portal user tried to reach something they are not allowed to.',
            '',
            '> **Who:** '.$this->text($name === '' ? 'Unnamed account' : $name).($idNo === '' ? '' : ' (ID no. '.$this->text($idNo).')'),
            '> **Role:** '.$this->text($role === '' ? 'None' : $role),
            '> **Tried:** `'.str_replace('`', '', $request->method().' /'.ltrim($request->path(), '/')).'`',
            '> **Refused because:** '.$this->text($reason),
            '> **From IP:** '.$this->text((string) ($request->ip() ?? 'unknown')),
            '> **When:** '.$this->timezone->now()->format('l, F j, Y \a\t g:i a'),
            '',
            'You get this because you hold Access alerts. The same attempt by the same person is reported at most once every 15 minutes.',
        ]);
    }

    /**
     * Each holder's work email, with the first name the letter greets them by.
     *
     * @return array<string, string>
     */
    private function recipients(Employee $actor): array
    {
        $ids = [];
        foreach (PortalRules::holders([self::ALERTS_RULE]) as $holder) {
            $ids[] = (int) $holder['employee']->getKey();
        }
        if ($ids === []) {
            return [];
        }

        $rows = Employee::query()
            ->toBase()
            ->whereIn('id', $ids)
            ->where('id', '!=', $actor->getKey())
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get(['email', 'first_name']);

        $unique = [];
        foreach ($rows as $row) {
            $email = trim((string) $row->email);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $unique[$email] ??= trim((string) $row->first_name);
        }

        return $unique;
    }

    private function text(string $value): string
    {
        return PortalEmailMarkup::escape((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));
    }
}
