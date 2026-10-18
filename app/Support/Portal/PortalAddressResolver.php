<?php

namespace App\Support\Portal;

/**
 * Reads a typed home address back into PSGC parts (2026-10-17, user). The address a person is hired
 * under is typed, not picked -- "377 Chipeco Avenue, Sitio Lote, Barangay Lecheria, Calamba City,
 * Laguna 4027 PHILIPPINES", or run together as "Brgy Dos Calamba City Laguna" -- so it is matched
 * against the PSGC lists to recover the province, city and barangay, and a clean geocoder query is
 * built from them: a city the list knows places far more reliably than the typed line, whose lot
 * numbers and streets throw OpenStreetMap.
 *
 * Matching only: the lists carry no coordinates, so the point still comes from the geocoder.
 */
final class PortalAddressResolver
{
    // Words that name a place's rank, not the place: "City of Calamba" is Calamba, "Barangay Lecheria"
    // is Lecheria. Stripped from both the typed part and the list's own name, so the two meet.
    private const RANK_PREFIX = '/^(?:city|municipality|municipal|barangay|brgy|bgy) (?:of )?/';

    private const RANK_SUFFIX = '/ (?:city|municipality)$/';

    // Letters Spanish-derived place names carry; folded so "Biñan" meets "Binan".
    private const FOLD = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    public function __construct(private readonly PortalPlaces $places) {}

    /**
     * @return array{province: ?string, provinceCode: ?string, city: ?string, cityCode: ?string, barangay: ?string, barangayCode: ?string, fromRegion: bool}
     */
    public function identify(string $address, ?string $region): array
    {
        $tokens = $this->tokens($address);

        $province = $this->match($this->provinces(), $tokens, null, true);
        $fromRegion = false;
        // The region it was filed under stands in for the province, and settles an ambiguous city.
        if ($province === null && $region !== null && trim($region) !== '') {
            $province = $this->match($this->provinces(), $this->tokens($region), null, true);
            $fromRegion = $province !== null;
        }
        $city = $this->match($this->cities(), $tokens, $province['code'] ?? null, true);
        // The city's own province settles it when the address never named one.
        if ($city !== null && $province === null) {
            $province = ['code' => $city['province_code'], 'name' => $this->provinceName($city['province_code'])];
        }
        $barangay = $city === null ? null : $this->match($this->barangays($city['code']), $tokens);

        return [
            'province' => $province['name'] ?? null,
            'provinceCode' => $province['code'] ?? null,
            'city' => $city['name'] ?? null,
            'cityCode' => $city['code'] ?? null,
            'barangay' => $barangay['name'] ?? null,
            'barangayCode' => $barangay['code'] ?? null,
            // Only the region named the province and the address named nothing at all: the query is the
            // region's, and the map reads it as such.
            'fromRegion' => $fromRegion && $city === null,
        ];
    }

    /**
     * The geocoder queries an address yields, most specific first, each ending ", Philippines".
     *
     * @return list<array{text: string, source: string}>
     */
    public function queries(string $address, ?string $region): array
    {
        $id = $this->identify($address, $region);
        $source = $id['fromRegion'] ? 'region' : 'address';
        $queries = [];
        foreach ([
            [$id['barangay'], $id['city'], $id['province']],
            [$id['city'], $id['province']],
            [$id['province']],
        ] as $names) {
            $kept = array_values(array_filter($names, static fn (?string $name): bool => $name !== null && $name !== ''));
            if ($kept !== []) {
                $text = implode(', ', $kept).', '.PortalPlaces::COUNTRY;
                $queries[$text] = ['text' => $text, 'source' => $source];
            }
        }

        return array_values($queries);
    }

    /**
     * The address's comma parts as matchable names: each folded, its rank words stripped, so
     * "City of Calamba" and "Calamba City" both read "calamba".
     *
     * @return list<string>
     */
    private function tokens(string $address): array
    {
        $tokens = [];
        foreach (PortalHomeLocations::addressParts($address) as $part) {
            foreach ([self::fold($part), self::base($part)] as $token) {
                if ($token !== '' && ! in_array($token, $tokens, true)) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * The list row one of the tokens names, preferring a given parent.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $tokens
     * @return array<string, string>|null
     */
    private function match(array $rows, array $tokens, ?string $preferredParent = null, bool $mustBeUnique = false): ?array
    {
        $matches = [];
        foreach ($rows as $row) {
            $name = self::base($row['name']);
            if ($name === '' || ! $this->names($tokens, $name)) {
                continue;
            }
            if ($preferredParent !== null && ($row['province_code'] ?? null) !== $preferredParent) {
                continue;
            }
            $matches[] = $row;
        }
        if ($matches === []) {
            return null;
        }

        // Two places share the name and nothing tells them apart: guessing could put someone in the
        // wrong province (Calamba is in Laguna and in Misamis Occidental), so the caller falls back to
        // the address as typed rather than move them somewhere wrong.
        return $mustBeUnique && count($matches) > 1 ? null : $matches[0];
    }

    /**
     * Whether a token names it whole -- so "Brgy Dos Calamba City Laguna", typed without commas, still
     * finds Calamba and Laguna.
     *
     * @param  list<string>  $tokens
     */
    private function names(array $tokens, string $name): bool
    {
        foreach ($tokens as $token) {
            if ($token === $name || str_contains(' '.$token.' ', ' '.$name.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function provinces(): array
    {
        return $this->places->provinces();
    }

    /**
     * @return list<array{code: string, name: string, province_code: string}>
     */
    private function cities(): array
    {
        return $this->places->allCities();
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function barangays(string $cityCode): array
    {
        return $this->places->barangays($cityCode);
    }

    private function provinceName(string $code): ?string
    {
        foreach ($this->provinces() as $row) {
            if ($row['code'] === $code) {
                return $row['name'];
            }
        }

        return null;
    }

    // Lowercased, folded and stripped of punctuation and spacing, so a name can be compared to a list.
    private static function fold(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), self::FOLD);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    // Folded, with the rank words dropped: "City of Calamba" and "Calamba City" both read "calamba".
    private static function base(string $value): string
    {
        $value = (string) preg_replace(self::RANK_PREFIX, '', self::fold($value));
        $value = (string) preg_replace(self::RANK_SUFFIX, '', $value);

        return trim($value);
    }
}
