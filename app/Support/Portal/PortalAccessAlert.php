<?php

namespace App\Support\Portal;

use App\Mail\PortalAccessAlertMail;
use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails Admin/Executive inboxes when a signed-in user hits a route they cannot use.
 *
 * Unauthenticated 401s are login noise and stay quiet. Mail is rate-limited per actor,
 * method, and path so a probe cannot become a mail bomb. The body never includes tokens.
 */
final class PortalAccessAlert
{
    public const CACHE_TTL_SECONDS = 900;

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

        $recipients = $this->recipientEmails($actor);
        if ($recipients === []) {
            return false;
        }

        try {
            Mail::to($recipients)->send(new PortalAccessAlertMail(
                employeeId: $actor->getKey(),
                idNo: $this->plain((string) $actor->id_no),
                name: $this->plain(trim((string) $actor->first_name.' '.(string) $actor->last_name)),
                role: $this->plain(trim((string) $actor->role.' / '.(string) $actor->role_level)),
                method: $request->method(),
                path: $request->path(),
                ip: (string) ($request->ip() ?? 'unknown'),
                reason: $this->plain($reason),
                occurredAt: now()->toIso8601String(),
            ));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function recipientEmails(Employee $actor): array
    {
        $emails = Employee::query()
            ->toBase()
            ->select(['id', 'email'])
            ->where('id', '!=', $actor->getKey())
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where(function ($builder): void {
                $builder->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
                    ->orWhereRaw("LOWER(COALESCE(role, '')) = 'admin'");
            })
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email');

        $unique = [];
        foreach ($emails as $email) {
            $trimmed = trim((string) $email);
            if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $unique[$trimmed] = $trimmed;
        }

        return array_values($unique);
    }

    private function plain(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    }
}
