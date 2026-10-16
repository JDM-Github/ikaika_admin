<?php

namespace Tests\Concerns;

use App\Modules\Core\Models\Action;
use App\Support\Airtable\PortalAirtableSync;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Every feature test as a live Airtable scenario (2026-10-14, user): with AIRTABLE_LIVE_SCENARIOS=1,
 * each test's own ledger actions are dry-run against the real base's schema before its transaction
 * rolls back, and a value Airtable would refuse -- a field it lacks, an option it does not offer, a
 * computed field -- fails the test. Nothing is ever sent. Links to rows a fixture made, which Airtable
 * never saw, are reported to storage/logs/airtable-live-scenarios.jsonl, not failed.
 */
trait ChecksAirtableLive
{
    private ?int $airtableLedgerMark = null;

    /** @var array<string, mixed>|null */
    private static ?array $airtableLiveSchema = null;

    private const REFUSED = ['has no field', 'has no option', 'is computed', 'has no table'];

    protected function markAirtableLedger(): void
    {
        if (getenv('AIRTABLE_LIVE_SCENARIOS') === '1') {
            $this->airtableLedgerMark = (int) Action::query()->max('id');
        }
    }

    protected function checkAirtableLive(): void
    {
        if ($this->airtableLedgerMark === null || str_contains(static::class, 'Airtable')) {
            return;
        }
        if (! Action::query()->where('id', '>', $this->airtableLedgerMark)->whereNull('synced_at')->exists()) {
            return;
        }

        // The real token and base, whatever a test set; the schema read once, then answered from it.
        $token = (string) env('AIRTABLE_TOKEN', '');
        $base = (string) env('AIRTABLE_BASE_ID', '');
        config(['services.airtable.token' => $token, 'services.airtable.base' => $base]);
        Http::swap(new Factory);
        self::$airtableLiveSchema ??= Http::withToken($token)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->get('https://api.airtable.com/v0/meta/bases/'.$base.'/tables')
            ->throw()
            ->json();
        Http::fake(['api.airtable.com/v0/meta/*' => Http::response(self::$airtableLiveSchema)]);

        $items = app(PortalAirtableSync::class)->run(false, 0, false, $this->airtableLedgerMark)['items'];
        $refused = [];
        foreach ($items as $item) {
            if ($item['problems'] === []) {
                continue;
            }
            file_put_contents(storage_path('logs/airtable-live-scenarios.jsonl'), json_encode([
                'test' => static::class.'::'.$this->name(),
                'resource' => $item['resource'],
                'outcome' => $item['outcome'],
                'record' => $item['recordId'],
                'problems' => $item['problems'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
            foreach ($item['problems'] as $problem) {
                // A lenient field left out is by design: logged above, never a failure.
                if (str_contains($problem, '" left out: ')) {
                    continue;
                }
                foreach (self::REFUSED as $marker) {
                    if (str_contains($problem, $marker)) {
                        $refused[] = $item['resource'].': '.$problem;
                    }
                }
            }
        }

        if ($refused !== []) {
            throw new AssertionFailedError("Airtable would refuse what this scenario ledgered:\n".implode("\n", array_unique($refused)));
        }
    }
}
