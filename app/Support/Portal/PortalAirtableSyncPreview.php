<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Airtable\PortalAirtableSync;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sync to Airtable from All Actions, as a dry run only (decided 2026-10-01): it shows what the next
 * send would do and writes nothing to Airtable or the ledger. Sending stays the
 * `portal:airtable-sync --send` command.
 *
 * Confirming it is them comes first (docs/power/location-and-accountability-plan.md, step 4): the
 * person types their Developer mode password, checked here, never logged. The preview reads the live
 * base's schema, so a failure there reads as a plain reason, never the token.
 */
final class PortalAirtableSyncPreview
{
    private const RESOURCE = 'administration.actions.sync';

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
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $this->developerMode->confirm($actor, $validated['password']);

        try {
            $report = $this->sync->run(false);
        } catch (Throwable $error) {
            abort(502, 'Airtable could not be read: '.$error->getMessage());
        }

        $items = [];
        foreach ($report['items'] as $item) {
            // Field values stay out: the preview says what would happen, All Actions already shows the change.
            $items[] = [
                'actionId' => (string) $item['actionId'],
                'resource' => $item['resource'],
                'actionType' => $item['actionType'],
                'recordId' => $item['recordId'],
                'outcome' => $item['outcome'],
                'table' => $item['table'],
                'problems' => $item['problems'],
            ];
        }

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            self::RESOURCE,
            PortalActivityCopy::previewedAirtableSync(count($items)),
            null,
            $request,
            ['counts' => $report['counts']],
        );

        return [
            'section' => 'administration',
            'resource' => 'airtable-sync-preview',
            'data' => ['counts' => $report['counts'], 'items' => $items],
        ];
    }
}
