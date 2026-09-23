<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * User / Notifications: the signed-in member's inbox for the shell header.
 */
final class PortalInbox
{
    public const CACHE_TTL_SECONDS = 30;

    public const DEFAULT_PAGE_SIZE = 5;

    /**
     * @var list<int>
     */
    public const PAGE_SIZES = [5];

    public function __construct(
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int, unread: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $timezone = $this->timezone->zone();
        $version = (int) Cache::get(PortalAudit::NOTIFICATION_CACHE_VERSION_KEY, 1);
        $key = 'portal:user:notifications:'.$version.':'.$actor->getKey().':'
            .md5($timezone->getName()).':'.$page.':'.$perPage;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $actor,
            $page,
            $perPage,
            $timezone,
        ): array {
            $employeeId = (int) $actor->getKey();
            $base = PortalNotification::query()
                ->where('employee_id', $employeeId);
            $total = (clone $base)->count();
            $unread = (clone $base)->whereNull('read_at')->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);

            $rows = (clone $base)
                ->select(PortalInboxPresenter::columns())
                ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->forPage($page, $perPage)
                ->get();

            $data = [];
            foreach ($rows as $row) {
                $data[] = PortalInboxPresenter::item($row, $timezone);
            }

            return [
                'section' => 'user',
                'resource' => 'notifications',
                'data' => $data,
                'counts' => [
                    'total' => $total,
                    'unread' => $unread,
                ],
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ],
            ];
        });
    }

    /**
     * @return array{section: string, resource: string, saved: bool}
     */
    public function markRead(Employee $actor, int $id): array
    {
        if ($id < 1) {
            abort(404, 'That notice was not found.');
        }

        if (! $this->audit->markRead($id, (int) $actor->getKey())) {
            abort(404, 'That notice was not found.');
        }

        return [
            'section' => 'user',
            'resource' => 'notifications',
            'saved' => true,
        ];
    }

    /**
     * @return array{section: string, resource: string, saved: int}
     */
    public function markAllRead(Employee $actor): array
    {
        $saved = PortalNotification::query()
            ->where('employee_id', (int) $actor->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($saved > 0) {
            $this->audit->bumpNotificationCache();
        }

        return [
            'section' => 'user',
            'resource' => 'notifications',
            'saved' => $saved,
        ];
    }

    private function perPage(Request $request): int
    {
        $value = $request->integer('per_page', self::DEFAULT_PAGE_SIZE);
        if (! in_array($value, self::PAGE_SIZES, true)) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return $value;
    }
}
