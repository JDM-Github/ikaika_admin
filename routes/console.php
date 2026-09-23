<?php

use App\Support\Airtable\PortalAirtableSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dry run unless --send is given: the default must never write to Airtable by accident.
Artisan::command('portal:airtable-sync {--send : Write to Airtable; without it nothing is written anywhere}', function (PortalAirtableSync $sync) {
    $send = (bool) $this->option('send');
    $this->info($send ? 'Sending unsynced portal actions to Airtable.' : 'DRY RUN -- nothing will be written to Airtable or the ledger.');

    $report = $sync->run($send);

    foreach ($report['items'] as $item) {
        $this->line(sprintf(
            '#%d  %-8s  %-22s  %-6s  record %-6s  -> %s%s',
            $item['actionId'],
            strtoupper($item['outcome']),
            (string) $item['resource'],
            $item['actionType'],
            (string) $item['recordId'],
            (string) $item['table'],
            $item['airtableId'] !== null ? ' '.$item['airtableId'] : '',
        ));
        if ($item['fields'] !== []) {
            $this->line('      fields: '.json_encode($item['fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        foreach ($item['problems'] as $problem) {
            $this->warn('      ! '.$problem);
        }
    }

    $counts = $report['counts'];
    $this->newLine();
    $this->info(sprintf(
        'create %d · update %d · discard %d · synced %d · covered %d · blocked %d · failed %d · skipped %d',
        $counts['create'], $counts['update'], $counts['discard'], $counts['synced'], $counts['covered'],
        $counts['blocked'], $counts['failed'], $counts['skipped'],
    ));

    return $counts['failed'] > 0 ? 1 : 0;
})->purpose('Replay unsynced portal ledger rows into Airtable (dry run unless --send)');
