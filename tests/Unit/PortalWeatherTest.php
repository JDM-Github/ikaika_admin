<?php

namespace Tests\Unit;

use App\Support\PortalWeather;
use Tests\TestCase;

class PortalWeatherTest extends TestCase
{
    public function test_weather_codes_map_to_a_kind_and_condition(): void
    {
        $this->assertSame('sun', PortalWeather::kind(0));
        $this->assertSame('Clear', PortalWeather::condition(0));
        $this->assertSame('rain', PortalWeather::kind(61));
        $this->assertSame('Light rain', PortalWeather::condition(61));
        $this->assertSame('storm', PortalWeather::kind(95));
        $this->assertSame('snow', PortalWeather::kind(71));
    }
}
