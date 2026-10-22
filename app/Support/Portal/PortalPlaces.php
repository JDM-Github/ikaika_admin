<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Philippines' provinces, its cities and municipalities, and its barangays (2026-10-13, user): the
 * PSA's Philippine Standard Geographic Code, kept in database/data/psgc and loaded into three tables,
 * for the registration form to pick an address from. Metro Manila stands in as a province for its
 * cities, and the two independent cities outside it sit under the province around them. A barangay
 * named twice in one city carries its count, so every pick reads apart.
 *
 * An address is kept in parts -- country, province, city, barangay, street -- and composed into the one
 * line New Employee Data and the employees row hold. In the Philippines each part is picked by its code
 * and named from the list; anywhere else they are typed.
 */
final class PortalPlaces
{
    public const COUNTRY = 'Philippines';

    /** Table => the CSV it loads from, parents first. */
    private const TABLES = [
        'psgc_provinces' => 'provinces.csv',
        'psgc_cities' => 'cities.csv',
        'psgc_barangays' => 'barangays.csv',
    ];

    private const CODE = '/^\d{9}$/';

    private const CACHE_VERSION_KEY = 'portal:places:version';

    private const CACHE_SECONDS = 86400;

    private const INSERT_BATCH = 1000;

    /**
     * Replaces the three tables with the CSVs, in one transaction.
     *
     * @return array<string, int> table => rows loaded
     */
    public function import(): array
    {
        $loaded = [];
        $this->connection()->transaction(function () use (&$loaded): void {
            foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                $this->connection()->table($table)->delete();
            }
            foreach (self::TABLES as $table => $file) {
                $rows = $this->rows($file);
                foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
                    $this->connection()->table($table)->insert($chunk);
                }
                $loaded[$table] = count($rows);
            }
        });
        Cache::forever(self::CACHE_VERSION_KEY, (int) Cache::get(self::CACHE_VERSION_KEY, 1) + 1);

        return $loaded;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function provinces(): array
    {
        return $this->remember('provinces', fn (): array => $this->list('psgc_provinces', null, null));
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function cities(string $provinceCode): array
    {
        $this->assertKnown('psgc_provinces', $provinceCode, 'That province is not on the list.');

        return $this->remember('cities:'.$provinceCode, fn (): array => $this->list('psgc_cities', 'province_code', $provinceCode));
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function barangays(string $cityCode): array
    {
        $this->assertKnown('psgc_cities', $cityCode, 'That city or municipality is not on the list.');

        return $this->remember('barangays:'.$cityCode, fn (): array => $this->list('psgc_barangays', 'city_code', $cityCode));
    }

    /**
     * Every city and municipality, whatever its province, for reading a typed address back into parts.
     *
     * @return list<array{code: string, name: string, province_code: string}>
     */
    public function allCities(): array
    {
        return $this->remember('cities:all', fn (): array => $this->connection()->table('psgc_cities')
            ->orderBy('name')
            ->get(['code', 'name', 'province_code'])
            ->map(static fn (object $row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'province_code' => (string) $row->province_code,
            ])
            ->all());
    }

    /**
     * One address as sent, its parts already cleaned: in the Philippines each picked code must sit in
     * the one above it, and its name is the list's; elsewhere the typed names stand. The line is null
     * until every part it needs is there -- in the Philippines a barangay too. A zip code, when given,
     * follows the province ("Laguna 4027"), as the country's own addresses write it (2026-10-20, user).
     *
     * @param  array<string, ?string>  $sent  country, province, provinceCode, city, cityCode, barangay, barangayCode, street, zip
     * @return array{parts: array<string, ?string>, line: ?string}
     */
    public function resolve(array $sent): array
    {
        $country = $sent['country'] ?? null;
        $street = $sent['street'] ?? null;
        $zip = $sent['zip'] ?? null;
        if ($country === self::COUNTRY) {
            $province = $this->pick('psgc_provinces', $sent['provinceCode'] ?? null, null, null, 'Choose the province again.');
            $city = $this->pick('psgc_cities', $sent['cityCode'] ?? null, 'province_code', $province, 'Choose the city or municipality again; it is not in that province.');
            $barangay = $this->pick('psgc_barangays', $sent['barangayCode'] ?? null, 'city_code', $city, 'Choose the barangay again; it is not in that city or municipality.');
            $parts = [
                'country' => $country,
                'province' => $province?->name, 'provinceCode' => $province?->code,
                'city' => $city?->name, 'cityCode' => $city?->code,
                'barangay' => $barangay?->name, 'barangayCode' => $barangay?->code,
                'street' => $street,
                'zip' => $zip,
            ];
            $needed = [$parts['province'], $parts['city'], $parts['barangay'], $street];
        } else {
            $parts = [
                'country' => $country,
                'province' => $sent['province'] ?? null, 'provinceCode' => null,
                'city' => $sent['city'] ?? null, 'cityCode' => null,
                'barangay' => $sent['barangay'] ?? null, 'barangayCode' => null,
                'street' => $street,
                'zip' => $zip,
            ];
            $needed = [$country, $parts['province'], $parts['city'], $street];
        }
        $isComplete = ! in_array(null, $needed, true);
        $line = $isComplete
            ? implode(', ', array_filter([
                $street, $parts['barangay'], $parts['city'], trim(($parts['province'] ?? '').' '.($zip ?? '')), $country,
            ], static fn (?string $part): bool => $part !== null && $part !== ''))
            : null;

        return ['parts' => $parts, 'line' => $line];
    }

    /**
     * A picked place, checked to sit under its parent; null when none was picked.
     */
    private function pick(string $table, ?string $code, ?string $parentColumn, ?object $parent, string $refusal): ?object
    {
        if ($code === null) {
            return null;
        }
        if (preg_match(self::CODE, $code) !== 1) {
            abort(422, $refusal);
        }
        $row = $this->connection()->table($table)->where('code', $code)->first();
        if ($row === null || ($parentColumn !== null && ($parent === null || $row->{$parentColumn} !== $parent->code))) {
            abort(422, $refusal);
        }

        return $row;
    }

    private function assertKnown(string $table, string $code, string $refusal): void
    {
        if (preg_match(self::CODE, $code) !== 1 || ! $this->connection()->table($table)->where('code', $code)->exists()) {
            abort(404, $refusal);
        }
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function list(string $table, ?string $parentColumn, ?string $parentCode): array
    {
        return $this->connection()->table($table)
            ->when($parentColumn !== null, fn ($query) => $query->where($parentColumn, $parentCode))
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(static fn (object $row): array => ['code' => (string) $row->code, 'name' => (string) $row->name])
            ->all();
    }

    /**
     * @param  callable(): list<array{code: string, name: string}>  $read
     * @return list<array{code: string, name: string}>
     */
    private function remember(string $key, callable $read): array
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);

        return Cache::remember('portal:places:'.$version.':'.$key, self::CACHE_SECONDS, $read);
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $file): array
    {
        $path = database_path('data/psgc/'.$file);
        $handle = is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException('The place list '.$file.' cannot be read.');
        }
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (is_array($header) && ($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        return $rows;
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
