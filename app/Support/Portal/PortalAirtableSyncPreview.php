<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Airtable\PortalAirtableSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sync to Airtable from All Actions: confirm it is you, read the dry run (writes nothing), then send.
 * The password is checked per request and never logged. Sending is the same engine and lock as
 * `portal:airtable-sync --send`.
 */
final class PortalAirtableSyncPreview
{
    private const RESOURCE = 'administration.actions.sync';

    private const LOCK_SECONDS = 900;

    private const RUN_SECONDS = 600;

    public function __construct(
        private readonly PortalAirtableSync $sync,
        private readonly PortalDeveloperMode $developerMode,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{section: string, resource: string, data: array{counts: array<string, int>, items: list<array<string, mixed>>}}
     */
    public function preview(Employee $actor, Request $request): array
    {
        @set_time_limit(0);
        $this->confirm($actor, $request);

        try {
            $report = $this->sync->run(false);
        } catch (Throwable $error) {
            abort(502, 'Airtable could not be read: '.$error->getMessage());
        }

        $data = ['counts' => $report['counts'], 'items' => $this->items($report['items'])];

        $this->audit->record($actor, PortalLogAction::POST, self::RESOURCE, PortalActivityCopy::previewedAirtableSync(count($data['items'])), null, $request, ['counts' => $report['counts']]);

        return ['section' => 'administration', 'resource' => 'airtable-sync-preview', 'data' => $data];
    }

    /**
     * @return array{section: string, resource: string, data: array{counts: array<string, int>, items: list<array<string, mixed>>, stopped: bool}}
     */
    public function send(Employee $actor, Request $request): array
    {
        @set_time_limit(0);
        $this->confirm($actor, $request);

        if (config('services.airtable.env') === 'prod' && ! app()->environment('production')) {
            abort(403, 'Refused: AIRTABLE_ENV=prod from a non-production app.');
        }

        $lock = Cache::lock('portal:airtable-sync', self::LOCK_SECONDS);
        if (! $lock->get()) {
            abort(409, 'Another Airtable send is already running; nothing was sent.');
        }

        try {
            $report = $this->sync->run(true, 10, false, null, false, self::RUN_SECONDS);
        } catch (Throwable $error) {
            abort(502, 'Airtable sync stopped: '.$error->getMessage());
        } finally {
            $lock->release();
        }

        $data = [
            'counts' => $report['counts'],
            'items' => $this->items($report['items']),
            'stopped' => (bool) ($report['stopped'] ?? false),
        ];
        $this->audit->record($actor, PortalLogAction::POST, self::RESOURCE, PortalActivityCopy::sentAirtableSync((int) $report['counts']['synced'], (int) $report['counts']['failed']), null, $request, ['counts' => $report['counts']]);

        return ['section' => 'administration', 'resource' => 'airtable-sync-sent', 'data' => $data];
    }

    private function confirm(Employee $actor, Request $request): void
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $this->developerMode->confirm($actor, $validated['password']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function items(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'actionId' => (string) $item['actionId'],
            'resource' => $item['resource'],
            'actionType' => $item['actionType'],
            'recordId' => $item['recordId'],
            'outcome' => $item['outcome'],
            'table' => $item['table'],
            'problems' => $item['problems'],
        ], $items);
    }
}
