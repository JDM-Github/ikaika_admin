<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalWeatherTest extends TestCase
{
    public function test_weather_requires_a_bearer_token(): void
    {
        $this->getJson('/api/development/portal/weather')
            ->assertUnauthorized();
    }

    public function test_weather_returns_manila_and_san_jose(): void
    {
        Cache::flush();

        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $isManila = str_contains((string) $request->url(), '14.5995');

            return Http::response([
                'current' => [
                    'temperature_2m' => $isManila ? 80.2 : 79.4,
                    'weather_code' => $isManila ? 61 : 0,
                ],
                'daily' => [
                    'temperature_2m_max' => [$isManila ? 85.0 : 79.0],
                    'temperature_2m_min' => [$isManila ? 80.0 : 56.0],
                ],
            ]);
        });

        $this->getJson('/api/development/portal/weather', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('locations.0.id', 'manila')
            ->assertJsonPath('locations.0.condition', 'Light rain')
            ->assertJsonPath('locations.0.kind', 'rain')
            ->assertJsonPath('locations.0.temperature_f', 80)
            ->assertJsonPath('locations.1.id', 'san-jose')
            ->assertJsonPath('locations.1.kind', 'sun')
            ->assertJsonPath('locations.1.temperature_f', 79);
    }

    public function test_weather_is_unavailable_when_the_provider_cannot_be_reached(): void
    {
        Cache::flush();

        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        Http::fake(function () {
            throw new ConnectionException('unavailable');
        });

        $this->getJson('/api/development/portal/weather', [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(503);
    }
}
