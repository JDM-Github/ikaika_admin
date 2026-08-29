<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

class PortalWeather
{
    /**
     * @return list<array{id: string, label: string, timezone: string, latitude: float, longitude: float}>
     */
    public static function offices(): array
    {
        return [
            [
                'id' => 'manila',
                'label' => 'Manila, Philippines',
                'timezone' => 'Asia/Manila',
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ],
            [
                'id' => 'san-jose',
                'label' => 'San Jose, CA',
                'timezone' => 'America/Los_Angeles',
                'latitude' => 37.3382,
                'longitude' => -121.8863,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshot(): array
    {
        $locations = [];
        foreach (self::offices() as $office) {
            $locations[] = $this->fetchOffice($office);
        }

        return $locations;
    }

    /**
     * @param  array{id: string, label: string, timezone: string, latitude: float, longitude: float}  $office
     * @return array<string, mixed>
     */
    private function fetchOffice(array $office): array
    {
        try {
            $request = Http::timeout(8);
            // Windows PHP often ships without a CA bundle; skip verify only on the local machine.
            if (app()->isLocal()) {
                $request = $request->withOptions(['verify' => false]);
            }
            $response = $request->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $office['latitude'],
                'longitude' => $office['longitude'],
                'current' => 'temperature_2m,weather_code',
                'daily' => 'temperature_2m_max,temperature_2m_min',
                'forecast_days' => 1,
                'temperature_unit' => 'fahrenheit',
                'timezone' => $office['timezone'],
            ]);
        } catch (Throwable) {
            abort(503, 'Weather is unavailable.');
        }

        if (! $response->successful()) {
            abort(503, 'Weather is unavailable.');
        }

        $payload = $response->json();
        $code = (int) data_get($payload, 'current.weather_code', 2);

        return [
            'id' => $office['id'],
            'label' => $office['label'],
            'timezone' => $office['timezone'],
            'temperature_f' => (int) round((float) data_get($payload, 'current.temperature_2m', 0)),
            'high_f' => (int) round((float) data_get($payload, 'daily.temperature_2m_max.0', 0)),
            'low_f' => (int) round((float) data_get($payload, 'daily.temperature_2m_min.0', 0)),
            'condition' => self::condition($code),
            'kind' => self::kind($code),
        ];
    }

    public static function kind(int $code): string
    {
        return match (true) {
            $code === 0, $code === 1 => 'sun',
            $code <= 3, $code === 45, $code === 48 => 'cloud',
            $code >= 71 && $code <= 77, $code === 85, $code === 86 => 'snow',
            $code >= 95 => 'storm',
            default => 'rain',
        };
    }

    public static function condition(int $code): string
    {
        return match (true) {
            $code === 0 => 'Clear',
            $code === 1 => 'Mainly clear',
            $code === 2 => 'Partly cloudy',
            $code === 3 => 'Overcast',
            $code === 45, $code === 48 => 'Fog',
            $code >= 51 && $code <= 57 => 'Drizzle',
            $code === 61 => 'Light rain',
            $code === 63 => 'Rain',
            $code === 65 => 'Heavy rain',
            $code >= 66 && $code <= 67 => 'Freezing rain',
            $code === 71 => 'Light snow',
            $code === 73 => 'Snow',
            $code === 75 => 'Heavy snow',
            $code >= 80 && $code <= 82 => 'Rain showers',
            $code >= 85 && $code <= 86 => 'Snow showers',
            $code >= 95 => 'Thunderstorm',
            default => 'Cloudy',
        };
    }
}
