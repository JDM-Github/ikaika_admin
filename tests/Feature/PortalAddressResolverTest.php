<?php

namespace Tests\Feature;

use App\Support\Portal\PortalAddressResolver;
use App\Support\Portal\PortalPlaces;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PortalAddressResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function resolver(): PortalAddressResolver
    {
        return new PortalAddressResolver(new PortalPlaces);
    }

    // The reported address (2026-10-17, user), read back into the city and barangay the list holds.
    public function test_a_typed_address_is_read_back_into_its_psgc_parts(): void
    {
        $id = $this->resolver()->identify(
            '377 Chipeco Avenue, Sitio Lote, Barangay Lecheria, Calamba City, Laguna 4027 PHILIPPINES',
            'Laguna',
        );

        $this->assertSame('Laguna', $id['province']);
        $this->assertSame('City of Calamba', $id['city']);
        $this->assertSame('Lecheria', $id['barangay']);
    }

    public function test_the_queries_lead_with_the_barangay_and_end_in_the_country(): void
    {
        $queries = $this->resolver()->queries(
            '377 Chipeco Avenue, Sitio Lote, Barangay Lecheria, Calamba City, Laguna 4027 PHILIPPINES',
            'Laguna',
        );

        $this->assertSame([
            ['text' => 'Lecheria, City of Calamba, Laguna, Philippines', 'source' => 'address'],
            ['text' => 'City of Calamba, Laguna, Philippines', 'source' => 'address'],
            ['text' => 'Laguna, Philippines', 'source' => 'address'],
        ], $queries);
    }

    // An address typed without commas still names its city and province (2026-10-17, user's data).
    public function test_an_address_run_together_still_finds_its_city(): void
    {
        $id = $this->resolver()->identify('Blk 9 Lot 18 Pabahay Phase 1 Brgy Dos Calamba City Laguna', null);

        $this->assertSame('Laguna', $id['province']);
        $this->assertSame('City of Calamba', $id['city']);
    }

    // Two cities named Calamba (Laguna and Misamis Occidental) and nothing to tell them apart: refuse
    // rather than move someone to the wrong province.
    public function test_an_ambiguous_city_with_no_province_is_left_unresolved(): void
    {
        $id = $this->resolver()->identify('Calamba', null);

        $this->assertNull($id['city']);
    }

    // A region filed without a street on the list still gives a province to open the map on.
    public function test_the_region_stands_in_when_the_address_names_nothing(): void
    {
        $id = $this->resolver()->identify('Blk 7 Nowhere Street', 'Laguna');

        $this->assertSame('Laguna', $id['province']);
        $this->assertNull($id['city']);
        $this->assertSame(
            [['text' => 'Laguna, Philippines', 'source' => 'region']],
            $this->resolver()->queries('Blk 7 Nowhere Street', 'Laguna'),
        );
    }

    public function test_nothing_on_the_list_leaves_every_part_empty(): void
    {
        $id = $this->resolver()->identify('123 Main Street', null);

        $this->assertNull($id['province']);
        $this->assertNull($id['city']);
        $this->assertSame([], $this->resolver()->queries('123 Main Street', null));
    }
}
