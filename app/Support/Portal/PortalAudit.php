<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Portal logs + notifications. Track member actions and drop inbox rows the
 * UI can open later via link_path + payload. Never store tokens or secrets.
 */
final class PortalAudit
{
    public const LOG_CACHE_VERSION_KEY = 'portal:user:logs:version';

    public const NOTIFICATION_CACHE_VERSION_KEY = 'portal:user:notifications:version';

    public function __construct(
        private readonly PortalLocationResolver $locationResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(
        int $employeeId,
        string $action,
        string $resource,
        ?string $recordId = null,
        ?string $message = null,
        array $payload = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $locationLabel = null,
        ?string $locationSource = null,
    ): int {
        $action = trim($action);
        $resource = trim($resource);
        if ($action === '' || $resource === '') {
            throw new InvalidArgumentException('Log action and resource are required.');
        }

        $log = new PortalLog;
        $log->employee_id = $employeeId;
        $log->action = $action;
        $log->resource = $resource;
        $log->record_id = $recordId;
        $log->message = $message;
        $log->ip_address = $ipAddress;
        $log->user_agent = $this->truncateUserAgent($userAgent);
        $log->location_label = $locationLabel;
        $log->location_source = $locationSource;
        $log->payload = $this->sanitizePayload($payload);
        $log->created_at = Carbon::now();
        $log->save();
        $this->bumpLogCache();

        return (int) $log->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Employee $owner,
        string $action,
        string $resource,
        string $message,
        ?string $recordId = null,
        ?Request $request = null,
        array $payload = [],
    ): int {
        $location = $this->locationResolver->resolve($request);

        return $this->log(
            (int) $owner->getKey(),
            $action,
            $resource,
            $recordId,
            $message,
            $payload,
            $request?->ip(),
            $request?->userAgent(),
            $location['label'],
            $location['source'],
        );
    }

    /**
     * Two rows for a decision: the requester's stream and the approver's stream.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordDecision(
        Employee $requester,
        Employee $approver,
        string $resource,
        string $subject,
        bool $approved,
        ?string $recordId = null,
        ?Request $request = null,
        array $payload = [],
    ): void {
        $verb = $approved ? 'approved' : 'rejected';
        $approverName = PortalActivityCopy::displayName($approver);
        $requesterName = PortalActivityCopy::displayName($requester);

        $this->record(
            $requester,
            PortalLogAction::PATCH,
            $resource,
            PortalActivityCopy::YOUR.' '.$subject.' has been '.$verb.' by '.$approverName,
            $recordId,
            $request,
            $payload,
        );
        $this->record(
            $approver,
            PortalLogAction::PATCH,
            $resource,
            PortalActivityCopy::YOU.' '.$verb.' '.$requesterName."'s ".$subject,
            $recordId,
            $request,
            $payload,
        );

        $this->notifyIfOther(
            $requester,
            $approver,
            $resource.'.'.$verb,
            $approved ? 'Request approved' : 'Request rejected',
            'Your '.$subject.' has been '.$verb.' by '.$approverName.'.',
            PortalShellPath::USER_REQUESTS,
            'Open requests',
            $recordId === null ? $payload : array_merge($payload, ['recordId' => $recordId]),
        );
    }

    /**
     * Inbox row for someone other than the actor. Same-person events stay in logs only.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifyIfOther(
        Employee $recipient,
        Employee $actor,
        string $type,
        string $title,
        string $message,
        ?string $linkPath = null,
        ?string $linkLabel = null,
        array $payload = [],
    ): void {
        if ((int) $recipient->getKey() === (int) $actor->getKey()) {
            return;
        }

        $this->notify(
            (int) $recipient->getKey(),
            $type,
            $title,
            $message,
            $linkPath,
            $linkLabel,
            $payload,
            (int) $actor->getKey(),
        );
    }

    /**
     * Inbox rows for every active Admin and Executive except the actor.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifyManagers(
        Employee $actor,
        string $type,
        string $title,
        string $message,
        ?string $linkPath = null,
        ?string $linkLabel = null,
        array $payload = [],
    ): void {
        $recipients = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where(function ($query): void {
                $query->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
                    ->orWhereRaw("LOWER(COALESCE(role, '')) = 'admin'");
            })
            ->get();

        foreach ($recipients as $recipient) {
            $this->notifyIfOther(
                $recipient,
                $actor,
                $type,
                $title,
                $message,
                $linkPath,
                $linkLabel,
                $payload,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function notify(
        int $employeeId,
        string $type,
        string $title,
        string $message,
        ?string $linkPath = null,
        ?string $linkLabel = null,
        array $payload = [],
        ?int $actorId = null,
    ): int {
        $type = trim($type);
        $title = trim($title);
        $message = trim($message);
        if ($type === '' || $title === '' || $message === '') {
            throw new InvalidArgumentException('Notification type, title, and message are required.');
        }

        $linkPath = $linkPath === null ? null : trim($linkPath);
        if ($linkPath === '') {
            $linkPath = null;
        }

        $notification = new PortalNotification;
        $notification->employee_id = $employeeId;
        $notification->actor_id = $actorId;
        $notification->type = $type;
        $notification->title = $title;
        $notification->message = $message;
        $notification->link_path = $linkPath;
        $notification->link_label = $linkLabel === null || trim($linkLabel) === '' ? null : trim($linkLabel);
        $notification->payload = $this->sanitizePayload($payload);
        $notification->read_at = null;
        $notification->created_at = Carbon::now();
        $notification->save();
        $this->bumpNotificationCache();

        return (int) $notification->id;
    }

    public function markRead(int $notificationId, int $employeeId): bool
    {
        $notification = PortalNotification::query()
            ->where('id', $notificationId)
            ->where('employee_id', $employeeId)
            ->first();

        if ($notification === null) {
            return false;
        }

        if ($notification->read_at !== null) {
            return true;
        }

        $notification->read_at = Carbon::now();
        $notification->save();
        $this->bumpNotificationCache();

        return true;
    }

    public function bumpNotificationCache(): void
    {
        $current = (int) Cache::get(self::NOTIFICATION_CACHE_VERSION_KEY, 1);
        Cache::forever(self::NOTIFICATION_CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $blocked = [
            'password', 'token', 'authorization', 'secret', 'api_key', 'apikey',
            'access_token', 'refresh_token', 'jwt', 'bank_account_number',
            'tax_identification_no', 'sss_no', 'philhealth_no', 'hdmf_no',
        ];

        $clean = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $trimmed = trim($userAgent);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 255);
    }

    private function bumpLogCache(): void
    {
        $current = (int) Cache::get(self::LOG_CACHE_VERSION_KEY, 1);
        Cache::forever(self::LOG_CACHE_VERSION_KEY, $current + 1);
    }
}
