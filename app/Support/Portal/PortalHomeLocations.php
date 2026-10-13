<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Where each active person lives, as a map point (2026-10-13, user): their address on record turned into
 * coordinates once through OpenStreetMap's Nominatim, and kept in employee_home_locations with the text
 * it came from, so only a new or changed address is looked up again. An address Nominatim cannot place
 * falls back to the person's region (employees.location). Nominatim allows one request a second.
 */
final class PortalHomeLocations
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    private const PAUSE_MICROSECONDS = 1_100_000;

    // The region field holds "NCR" for Metro Manila, which Nominatim does not read.
    private const REGION_QUERIES = ['ncr' => 'Metro Manila, Philippines', 'usa' => 'United States'];

    public function __construct(private readonly bool $pauses = true) {}

    /**
     * Looks up every active person whose address or region is new or changed since their point was kept.
     *
     * @return array{placed: int, failed: int, unchanged: int}
     */
    public function refresh(): array
    {
        $kept = $this->connection()->table('employee_home_locations')->get()->keyBy('employee_id');
        $counts = ['placed' => 0, 'failed' => 0, 'unchanged' => 0];
        $asked = false;
        foreach ($this->activePeople() as $person) {
            $queries = self::queries($person);
            if ($queries === []) {
                continue;
            }
            $previous = $kept[(int) $person->id] ?? null;
            if ($previous !== null && $previous->query === $queries[0]['text']) {
                $counts['unchanged']++;

                continue;
            }
            $point = null;
            foreach ($queries as $query) {
                if ($asked && $this->pauses) {
                    usleep(self::PAUSE_MICROSECONDS);
                }
                $asked = true;
                $point = $this->lookUp($query['text']);
                if ($point !== null) {
                    $point['source'] = $query['source'];
                    break;
                }
            }
            if ($point === null) {
                $counts['failed']++;

                continue;
            }
            $this->connection()->table('employee_home_locations')->updateOrInsert(
                ['employee_id' => (int) $person->id],
                [
                    'query' => $queries[0]['text'],
                    'source' => $point['source'],
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'geocoded_at' => now(),
                ],
            );
            $counts['placed']++;
        }

        return $counts;
    }

    /**
     * The address first, then the region, each as the text Nominatim is asked.
     *
     * @return list<array{text: string, source: string}>
     */
    private static function queries(object $person): array
    {
        $queries = [];
        $address = trim((string) preg_replace('/\s+/u', ' ', (string) ($person->address ?? '')));
        if ($address !== '') {
            $queries[] = ['text' => $address, 'source' => 'address'];
            // Lot and block numbers throw Nominatim, so the address is tried again from its end: the
            // subdivision or barangay with the town and province, then the town and province alone.
            $parts = self::addressParts($address);
            foreach ([3, 2] as $tail) {
                if (count($parts) >= $tail) {
                    $text = implode(', ', array_slice($parts, -$tail)).', Philippines';
                    if (! in_array($text, array_column($queries, 'text'), true)) {
                        $queries[] = ['text' => $text, 'source' => 'address'];
                    }
                }
            }
        }
        $region = trim((string) ($person->location ?? ''));
        if ($region !== '') {
            $queries[] = ['text' => self::REGION_QUERIES[strtolower($region)] ?? $region.', Philippines', 'source' => 'region'];
        }

        return $queries;
    }

    /**
     * An address's comma parts without the postcode or the country, which every query adds back itself.
     *
     * @return list<string>
     */
    public static function addressParts(string $address): array
    {
        return array_values(array_filter(
            array_map(static fn (string $part): string => trim((string) preg_replace('/\s+\d{4}$/', '', trim($part))), explode(',', $address)),
            static fn (string $part): bool => $part !== '' && preg_match('/^\(?\d+\)?(\s*Philippines)?$/i', $part) !== 1 && strcasecmp($part, 'Philippines') !== 0,
        ));
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function lookUp(string $query): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'IKAIKA Portal ('.config('app.url').')'])
                ->get(self::ENDPOINT, ['q' => $query, 'format' => 'json', 'limit' => 1]);
        } catch (Throwable) {
            return null;
        }
        $first = $response->successful() ? ($response->json()[0] ?? null) : null;
        if (! is_array($first) || ! is_numeric($first['lat'] ?? null) || ! is_numeric($first['lon'] ?? null)) {
            return null;
        }

        return ['latitude' => (float) $first['lat'], 'longitude' => (float) $first['lon']];
    }

    /**
     * @return list<object>
     */
    private function activePeople(): array
    {
        return $this->connection()->table('employees')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->orderBy('id')
            ->get(['id', 'address', 'location'])
            ->all();
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
