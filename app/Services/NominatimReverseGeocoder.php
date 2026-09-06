<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NominatimReverseGeocoder
{
    public function reverse(float $latitude, float $longitude): ?array
    {
        $cacheKey = sprintf('nominatim:reverse:en:%.5f:%.5f', $latitude, $longitude);

        try {
            return Cache::remember($cacheKey, now()->addDays(30), function () use ($latitude, $longitude) {
                return Cache::lock('nominatim:request', 10)->block(10, function () use ($latitude, $longitude) {
                    $lastRequestAt = (float) Cache::get('nominatim:last-request-at', 0);
                    $remainingMicroseconds = (int) max(0, (1 - (microtime(true) - $lastRequestAt)) * 1_000_000);
                    if ($remainingMicroseconds > 0) {
                        usleep($remainingMicroseconds);
                    }

                    try {
                        $response = Http::acceptJson()
                            ->withUserAgent(config('services.nominatim.user_agent'))
                            ->timeout(5)
                            ->get(config('services.nominatim.url'), [
                                'lat' => $latitude,
                                'lon' => $longitude,
                                'format' => 'jsonv2',
                                'addressdetails' => 1,
                                'layer' => 'address',
                                'accept-language' => 'en',
                            ]);
                    } catch (\Throwable) {
                        return null;
                    } finally {
                        Cache::forever('nominatim:last-request-at', microtime(true));
                    }

                    if (! $response->successful()) {
                        return null;
                    }

                    $address = $response->json('address', []);
                    $city = $address['city'] ?? $address['town'] ?? $address['municipality'] ?? $address['village'] ?? null;
                    $suburb = $address['suburb'] ?? $address['city_district'] ?? $address['borough'] ?? $address['neighbourhood'] ?? $address['residential'] ?? null;

                    if (! $city && ! $suburb) {
                        return null;
                    }

                    return ['city' => $city, 'suburb' => $suburb];
                });
            });
        } catch (\Throwable) {
            return null;
        }
    }
}
