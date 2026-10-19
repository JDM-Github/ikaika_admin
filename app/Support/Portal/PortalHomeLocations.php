<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Where each active person lives, as a map point (2026-10-13, user). Each member and administrator sets
 * their own home after signing in, by GPS or by a pin on the map (sources gps and map); executives are
 * not asked. Until they do, their address on record stands in: turned into coordinates once through
 * OpenStreetMap's Nominatim (sources address and region), and looked up again only when it changes. A
 * home a person set is never overwritten by the address, and the address's own point is kept apart in
 * the suggested_* columns, so "Use default" always returns to their data. Nominatim allows one request
 * a second.
 */
final class PortalHomeLocations
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    // What sits at a point, for Profile's Use my pin (2026-10-19, user).
    private const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    // An address's own parts, by the keys Nominatim names them under, most specific first.
    private const PROVINCE_KEYS = ['province', 'state', 'county', 'region'];

    private const CITY_KEYS = ['city', 'town', 'municipality'];

    private const BARANGAY_KEYS = ['village', 'suburb', 'quarter', 'neighbourhood', 'hamlet'];

    // What is not part of the place itself, so is left out of the line the PSGC lists are matched against.
    private const NOT_PLACES = ['country', 'country_code', 'postcode', 'road', 'house_number'];

    private const PAUSE_MICROSECONDS = 1_100_000;

    // The region field holds "NCR" for Metro Manila, which Nominatim does not read.
    private const REGION_QUERIES = ['ncr' => 'Metro Manila, Philippines', 'usa' => 'United States'];

    // The ways a person sets their home themselves, the only ones the portal may send.
    public const PICKED_SOURCES = ['gps', 'map'];

    // A home moved to a new address a head admin approved (2026-10-19, user), kept as theirs like a pin.
    public const APPROVED_SOURCE = 'approved';

    // Homes a person set themselves, or had moved with an approved address; the address lookup leaves these alone.
    public const OWN_SOURCES = [...self::PICKED_SOURCES, self::APPROVED_SOURCE];

    public function __construct(
        private readonly PortalAddressResolver $resolver,
        private readonly bool $pauses = true,
    ) {}

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
            $queries = $this->queries($person);
            if ($queries === []) {
                continue;
            }
            $previous = $kept[(int) $person->id] ?? null;
            // The address's own line is what is looked up, so it is also what says nothing changed.
            if ($previous !== null && $previous->suggested_query === $queries[0]['text']) {
                $counts['unchanged']++;

                continue;
            }
            $point = $this->place($queries, $asked);
            if ($point === null) {
                $counts['failed']++;

                continue;
            }
            $own = $previous !== null && in_array($previous->source, self::OWN_SOURCES, true);
            $payload = [
                'suggested_query' => $queries[0]['text'],
                'suggested_latitude' => $point['latitude'],
                'suggested_longitude' => $point['longitude'],
            ];
            // Their own home is the point the map shows; the address only stands in until they set one.
            if (! $own) {
                $payload += [
                    'query' => $queries[0]['text'],
                    'source' => $point['source'],
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'geocoded_at' => now(),
                ];
            }
            $this->connection()->table('employee_home_locations')->updateOrInsert(['employee_id' => (int) $person->id], $payload);
            $counts['placed']++;
        }

        return $counts;
    }

    /**
     * The signed-in person's home, whether they still have to set it, and where the picker starts: their
     * own home, else the point their address gave, else nothing. The address's own canonical line rides
     * along as `suggestedLabel`, for the picker's search box to open on.
     *
     * @return array{home: array{latitude: float, longitude: float, method: string}|null, required: bool, suggested: array{latitude: float, longitude: float}|null, suggestedLabel: string|null}
     */
    public function mine(object $employee): array
    {
        $row = $this->connection()->table('employee_home_locations')->where('employee_id', (int) $employee->id)->first();
        $own = $row !== null && in_array($row->source, self::OWN_SOURCES, true);

        // The address on record's own point, which survives them setting a home. Its own columns hold
        // it; a row geocoded before those existed still reads from the row's point.
        $suggested = null;
        $label = null;
        if ($row !== null) {
            if ($row->suggested_latitude !== null && $row->suggested_longitude !== null) {
                $suggested = ['latitude' => (float) $row->suggested_latitude, 'longitude' => (float) $row->suggested_longitude];
                $label = trim((string) $row->suggested_query);
            } elseif (! $own) {
                $suggested = ['latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude];
                $label = trim((string) $row->query);
            }
        }

        return [
            'home' => $own ? ['latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude, 'method' => (string) $row->source] : null,
            // Executives and the location-exempt are not asked; everyone else sets theirs before using the portal.
            'required' => ! $own && ! PortalRole::isExecutive($employee->role ?? null, $employee->role_level ?? null)
                && ! PortalLocationExemptions::covers($employee),
            'suggested' => $suggested,
            'suggestedLabel' => $label === null || $label === '' ? null : $label,
        ];
    }

    /**
     * Their home follows a new address on record once a head admin approved it (2026-10-19, user): the
     * address is placed now, and its point becomes their home, kept as theirs, and their address's own
     * point too. False, and their home left where it was, when the address cannot be placed.
     */
    public function followAddress(int $employeeId): bool
    {
        if (PortalLocationExemptions::covers($employeeId)) {
            return false;
        }
        $person = $this->connection()->table('employees')->where('id', $employeeId)->first(['id', 'address', 'location']);
        $queries = $person === null ? [] : $this->queries($person);
        $asked = false;
        $point = $queries === [] ? null : $this->place($queries, $asked);
        if ($point === null) {
            return false;
        }
        $this->connection()->table('employee_home_locations')->updateOrInsert(['employee_id' => $employeeId], [
            'query' => $queries[0]['text'],
            'source' => self::APPROVED_SOURCE,
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'geocoded_at' => now(),
            'suggested_query' => $queries[0]['text'],
            'suggested_latitude' => $point['latitude'],
            'suggested_longitude' => $point['longitude'],
        ]);

        return true;
    }

    /**
     * The first of the queries Nominatim places, one request a second.
     *
     * @param  list<array{text: string, source: string}>  $queries
     * @return array{latitude: float, longitude: float, source: string}|null
     */
    private function place(array $queries, bool &$asked): ?array
    {
        foreach ($queries as $query) {
            if ($asked && $this->pauses) {
                usleep(self::PAUSE_MICROSECONDS);
            }
            $asked = true;
            $point = $this->lookUp($query['text']);
            if ($point !== null) {
                return $point + ['source' => $query['source']];
            }
        }

        return null;
    }

    /**
     * The address at their own pin (2026-10-19, user), for Profile's edit to fill its parts from: in the
     * Philippines the province, city and barangay the PSGC lists name there, elsewhere the names
     * Nominatim gives, and the house number and road as the street. Null with no pin of their own, or
     * nothing found there.
     *
     * @return array{country: ?string, provinceCode: ?string, province: ?string, cityCode: ?string, city: ?string, barangayCode: ?string, barangay: ?string, street: ?string}|null
     */
    public function addressAtHome(object $employee): ?array
    {
        $home = $this->mine($employee)['home'];
        if ($home === null) {
            return null;
        }
        try {
            $response = $this->nominatim()->get(self::REVERSE_ENDPOINT, [
                'lat' => $home['latitude'], 'lon' => $home['longitude'], 'format' => 'json',
                'addressdetails' => 1, 'zoom' => 18, 'accept-language' => 'en',
            ]);
        } catch (Throwable) {
            return null;
        }
        $address = $response->successful() ? ($response->json()['address'] ?? null) : null;
        if (! is_array($address) || $address === []) {
            return null;
        }
        $first = static function (array $keys) use ($address): ?string {
            foreach ($keys as $key) {
                $value = $address[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return null;
        };
        $street = trim(implode(' ', array_filter([$first(['house_number']), $first(['road'])])));
        $street = $street === '' ? null : $street;

        if (strtolower((string) ($address['country_code'] ?? '')) === 'ph') {
            $names = [];
            foreach ($address as $key => $value) {
                if (is_string($value) && ! in_array($key, self::NOT_PLACES, true) && ! str_starts_with((string) $key, 'ISO')) {
                    $names[] = $value;
                }
            }
            $place = $this->resolver->identify(implode(', ', $names), null);

            return [
                'country' => 'Philippines',
                'provinceCode' => $place['provinceCode'], 'province' => $place['province'],
                'cityCode' => $place['cityCode'], 'city' => $place['city'],
                'barangayCode' => $place['barangayCode'], 'barangay' => $place['barangay'],
                'street' => $street,
            ];
        }

        return [
            'country' => $first(['country']),
            'provinceCode' => null, 'province' => $first(self::PROVINCE_KEYS),
            'cityCode' => null, 'city' => $first(self::CITY_KEYS),
            'barangayCode' => null, 'barangay' => $first(self::BARANGAY_KEYS),
            'street' => $street,
        ];
    }

    // Kept as the person's own home: the address lookup will not move it again. The address on
    // record's own point (suggested_*) is left untouched, so Use default still returns to their data.
    public function setOwn(int $employeeId, float $latitude, float $longitude, string $method): void
    {
        if (PortalLocationExemptions::covers($employeeId)) {
            abort(422, 'Your location is not recorded, so there is no home to set.');
        }
        $this->connection()->table('employee_home_locations')->updateOrInsert(
            ['employee_id' => $employeeId],
            ['query' => $method, 'source' => $method, 'latitude' => $latitude, 'longitude' => $longitude, 'geocoded_at' => now()],
        );
    }

    /**
     * The address's own PSGC parts first, then the typed line, then the region, each as the text
     * Nominatim is asked. The canonical parts lead because a city the list knows places far more
     * reliably than the typed line, whose lot numbers and streets throw Nominatim.
     *
     * @return list<array{text: string, source: string}>
     */
    private function queries(object $person): array
    {
        $queries = [];
        $seen = [];
        $push = static function (string $text, string $source) use (&$queries, &$seen): void {
            if ($text !== '' && ! isset($seen[$text])) {
                $seen[$text] = true;
                $queries[] = ['text' => $text, 'source' => $source];
            }
        };

        $address = trim((string) preg_replace('/\s+/u', ' ', (string) ($person->address ?? '')));
        $region = trim((string) ($person->location ?? ''));
        if ($address !== '') {
            foreach ($this->resolver->queries($address, $region === '' ? null : $region) as $query) {
                $push($query['text'], $query['source']);
            }
            $push($address, 'address');
        } elseif ($region !== '') {
            // No address to read: the region's own province is all there is.
            foreach ($this->resolver->queries('', $region) as $query) {
                $push($query['text'], $query['source']);
            }
        }
        if ($region !== '') {
            $push(self::REGION_QUERIES[strtolower($region)] ?? $region.', Philippines', 'region');
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
            array_map(static function (string $part): string {
                $part = (string) preg_replace('/\bphilippines\b/i', ' ', trim($part));
                // A four-digit run is the postcode, not a house number; dropped wherever it sits.
                $part = (string) preg_replace(['/\b\d{4}\b/', '/[()]/'], ' ', $part);

                return trim((string) preg_replace('/\s+/', ' ', $part));
            }, explode(',', $address)),
            static fn (string $part): bool => $part !== '' && preg_match('/^\(?\d+\)?$/', $part) !== 1,
        ));
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function lookUp(string $query): ?array
    {
        try {
            $response = $this->nominatim()->get(self::ENDPOINT, ['q' => $query, 'format' => 'json', 'limit' => 1]);
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
            // The location-exempt are never placed, not even from their address on record.
            ->whereNotIn('id', PortalLocationExemptions::ids())
            ->orderBy('id')
            ->get(['id', 'address', 'location'])
            ->all();
    }

    /*
     * Nominatim asks every caller to name itself. Windows PHP often ships without curl.cainfo, so the
     * certificate is checked against Composer's CA bundle, as every other call out of the portal is.
     */
    private function nominatim(): PendingRequest
    {
        return Http::timeout(15)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->withHeaders(['User-Agent' => 'IKAIKA Portal ('.config('app.url').')']);
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
