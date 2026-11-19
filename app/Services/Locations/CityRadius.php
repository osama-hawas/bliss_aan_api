<?php

namespace App\Services\Locations;

use App\Models\City;
use App\Services\Settings\AppSettings;

/**
 * Whether a point is close enough to its city to be an event or delivery address there (QA BUG-006: a Riyadh request
 * was saved ~80 km away in Al Kharj). Distance is measured from the city's center; a city without a center, or the
 * setting `requests.max_distance_from_city_km` left empty, means no limit.
 */
class CityRadius
{
    public function __construct(private readonly AppSettings $settings) {}

    public function allows(int $cityId, float $lat, float $lng): bool
    {
        $max = $this->settings->get('requests.max_distance_from_city_km');
        if ($max === null) {
            return true;
        }
        $city = City::query()->find($cityId, ['id', 'center_lat', 'center_lng']);
        if ($city?->center_lat === null || $city->center_lng === null) {
            return true;
        }

        return self::km((float) $city->center_lat, (float) $city->center_lng, $lat, $lng) <= (float) $max;
    }

    /** Great-circle distance in km (haversine). */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
