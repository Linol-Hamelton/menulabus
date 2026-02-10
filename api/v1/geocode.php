<?php
/**
 * Reverse-geocoding proxy → Nominatim.
 * Avoids browser CORS issues by making the request server-side.
 * GET /api/v1/geocode.php?lat=…&lon=…
 */
require_once __DIR__ . '/bootstrap.php';
api_v1_require_method('GET');

$lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lon = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);

if ($lat === false || $lat === null || $lon === false || $lon === null) {
    ApiResponse::error('Missing or invalid lat/lon', 400);
}
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    ApiResponse::error('Coordinates out of range', 400);
}

$url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
    'format' => 'json',
    'lat'    => $lat,
    'lon'    => $lon,
    'accept-language' => 'ru',
]);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: MenuLabus/1.0 (https://menu.labus.pro)\r\n",
        'timeout' => 5,
    ],
]);

$response = @file_get_contents($url, false, $ctx);
if ($response === false) {
    ApiResponse::error('Geocoding service unavailable', 502);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    ApiResponse::error('Invalid geocoding response', 502);
}

$addr = $data['address'] ?? [];
$city         = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? '';
$road         = $addr['road'] ?? '';
$houseNumber  = $addr['house_number'] ?? '';

$parts = array_filter([$city, $road, $houseNumber]);
$displayName = $parts ? implode(', ', $parts) : "{$lat}, {$lon}";

ApiResponse::success([
    'display_name' => $displayName,
    'city'         => $city,
    'road'         => $road,
    'house_number' => $houseNumber,
    'lat'          => $lat,
    'lon'          => $lon,
]);
