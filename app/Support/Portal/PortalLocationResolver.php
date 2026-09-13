<?php

namespace App\Support\Portal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PortalLocationResolver
{
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * @return array{label: ?string, source: ?string}
     */
    public function resolve(?Request $request): array
    {
        if ($request === null) {
            return ['label' => null, 'source' => null];
        }

        $deviceLabel = $this->clean($request->header('X-Portal-Location'));
        if ($deviceLabel !== null) {
            $source = $this->clean($request->header('X-Portal-Location-Source'));

            return [
                'label' => $deviceLabel,
                'source' => $source === 'ip' ? 'ip' : 'device',
            ];
        }

        $ipAddress = $request->ip();
        if ($this->isPublicIp($ipAddress)) {
            $ipLabel = $this->lookupUrlsFor($ipAddress);
        } elseif ($this->lookupPrivate()) {
            $ipLabel = $this->lookupUrlsFor(null);
        } else {
            $ipLabel = null;
        }

        return [
            'label' => $ipLabel,
            'source' => $ipLabel === null ? null : 'ip',
        ];
    }

    private function lookupPrivate(): bool
    {
        return (bool) config('services.ipwhois.lookup_private');
    }

    private function lookupUrlsFor(?string $ipAddress): ?string
    {
        $cacheKey = 'portal:location:ip:'.hash('sha256', $ipAddress ?? 'egress');
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        foreach ($this->lookupUrls($ipAddress) as $url) {
            $label = $this->requestLabel($url);
            if ($label !== null) {
                Cache::put($cacheKey, $label, self::CACHE_TTL_SECONDS);

                return $label;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function lookupUrls(?string $ipAddress): array
    {
        $whois = rtrim((string) config('services.ipwhois.base_url'), '/');
        $ipapi = rtrim((string) config('services.ipapi.base_url'), '/');
        if ($ipAddress === null) {
            return array_values(array_filter([$whois !== '' ? $whois.'/' : null]));
        }

        $encoded = rawurlencode($ipAddress);

        return array_values(array_filter([
            $whois !== '' ? $whois.'/'.$encoded : null,
            $ipapi !== '' ? $ipapi.'/'.$encoded.'/json/' : null,
        ]));
    }

    private function requestLabel(string $url): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout(3)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ($payload['error'] ?? false) === true || ($payload['success'] ?? true) === false) {
                return null;
            }

            return $this->label([
                $payload['city'] ?? null,
                $payload['region'] ?? null,
                $payload['country_name'] ?? $payload['country'] ?? null,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function isPublicIp(?string $ipAddress): bool
    {
        return $ipAddress !== null
            && filter_var(
                $ipAddress,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
    }

    /**
     * @param  list<mixed>  $parts
     */
    private function label(array $parts): ?string
    {
        $cleaned = [];
        foreach ($parts as $part) {
            $value = $this->clean($part);
            if ($value !== null && ! in_array($value, $cleaned, true)) {
                $cleaned[] = $value;
            }
        }

        return $cleaned === [] ? null : mb_substr(implode(', ', $cleaned), 0, 255);
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $value === '' ? null : mb_substr($value, 0, 80);
    }
}
