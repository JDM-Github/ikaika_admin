<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PortalWeather;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PortalWeatherController extends Controller
{
    public function __construct(private readonly PortalWeather $weather) {}

    public function show(): JsonResponse
    {
        $locations = Cache::remember('portal.weather', 600, fn (): array => $this->weather->snapshot());

        return response()->json([
            'locations' => $locations,
        ]);
    }
}
