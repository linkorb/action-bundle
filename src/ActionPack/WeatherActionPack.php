<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\ActionPack;

use LinkORB\Component\Action\Attribute\Action;
use LinkORB\Component\Action\Attribute\ActionPack;
use LinkORB\Component\Action\Host\HostInterface;

/**
 * ActionPack for weather-related operations using Open-Meteo API
 *
 * @see https://open-meteo.com/en/docs
 */
#[ActionPack(
    name: 'weather',
    description: 'Weather-related actions for current conditions and forecasts using Open-Meteo API'
)]
class WeatherActionPack
{
    private const API_BASE = 'https://api.open-meteo.com/v1/forecast';

    /**
     * WMO Weather interpretation codes
     * @see https://open-meteo.com/en/docs#weathervariables
     */
    private const WEATHER_CODES = [
        0 => 'Clear sky',
        1 => 'Mainly clear',
        2 => 'Partly cloudy',
        3 => 'Overcast',
        45 => 'Fog',
        48 => 'Depositing rime fog',
        51 => 'Light drizzle',
        53 => 'Moderate drizzle',
        55 => 'Dense drizzle',
        56 => 'Light freezing drizzle',
        57 => 'Dense freezing drizzle',
        61 => 'Slight rain',
        63 => 'Moderate rain',
        65 => 'Heavy rain',
        66 => 'Light freezing rain',
        67 => 'Heavy freezing rain',
        71 => 'Slight snow fall',
        73 => 'Moderate snow fall',
        75 => 'Heavy snow fall',
        77 => 'Snow grains',
        80 => 'Slight rain showers',
        81 => 'Moderate rain showers',
        82 => 'Violent rain showers',
        85 => 'Slight snow showers',
        86 => 'Heavy snow showers',
        95 => 'Thunderstorm',
        96 => 'Thunderstorm with slight hail',
        99 => 'Thunderstorm with heavy hail',
    ];

    /**
     * Get current weather for a location by latitude/longitude
     *
     * @param array{latitude: float, longitude: float, units?: string} $input
     * @return array{latitude: float, longitude: float, temperature: float, unit: string, conditions: string, weatherCode: int, windSpeed: float, windUnit: string, windDirection: int, isDay: bool, time: string}
     */
    #[Action(
        name: 'current',
        description: 'Get current weather conditions for a location',
        attribute: 'execute',
        subject: 'weather',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'latitude' => [
                    'type' => 'number',
                    'description' => 'Latitude coordinate',
                    'examples' => [52.52, 51.85],
                ],
                'longitude' => [
                    'type' => 'number',
                    'description' => 'Longitude coordinate',
                    'examples' => [13.41, 4.49],
                ],
                'units' => [
                    'type' => 'string',
                    'description' => 'Temperature units (celsius or fahrenheit)',
                    'default' => 'celsius',
                    'examples' => ['celsius'],
                ],
            ],
            'required' => ['latitude', 'longitude'],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'latitude' => ['type' => 'number', 'description' => 'Latitude coordinate'],
                'longitude' => ['type' => 'number', 'description' => 'Longitude coordinate'],
                'temperature' => ['type' => 'number', 'description' => 'Current temperature'],
                'unit' => ['type' => 'string', 'description' => 'Temperature unit'],
                'conditions' => ['type' => 'string', 'description' => 'Weather conditions description'],
                'weatherCode' => ['type' => 'integer', 'description' => 'WMO weather code'],
                'windSpeed' => ['type' => 'number', 'description' => 'Wind speed'],
                'windUnit' => ['type' => 'string', 'description' => 'Wind speed unit'],
                'windDirection' => ['type' => 'integer', 'description' => 'Wind direction in degrees'],
                'isDay' => ['type' => 'boolean', 'description' => 'Whether it is currently daytime'],
                'time' => ['type' => 'string', 'description' => 'Observation time in ISO 8601 format'],
            ],
            'required' => ['latitude', 'longitude', 'temperature', 'unit', 'conditions', 'weatherCode', 'windSpeed', 'windUnit', 'windDirection', 'isDay', 'time'],
        ]
    )]
    public function current(array $input, HostInterface $host): array
    {
        $latitude = (float) $input['latitude'];
        $longitude = (float) $input['longitude'];
        $units = (string) ($input['units'] ?? 'celsius');

        $host->info("Fetching current weather for coordinates: {$latitude}, {$longitude}");
        $host->debug("Using units: {$units}");

        // Build API URL
        $params = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current_weather' => 'true',
        ];

        if ($units === 'fahrenheit') {
            $params['temperature_unit'] = 'fahrenheit';
            $params['windspeed_unit'] = 'mph';
        }

        $url = self::API_BASE . '?' . http_build_query($params);
        $host->debug("API URL: {$url}");

        $response = $this->fetchJson($url);

        if (!isset($response['current_weather'])) {
            throw new \RuntimeException('Invalid API response: missing current_weather data');
        }

        $weather = $response['current_weather'];
        $weatherCode = (int) $weather['weathercode'];
        $conditions = self::WEATHER_CODES[$weatherCode] ?? 'Unknown';

        $host->info("Weather data retrieved successfully: {$conditions}");

        return [
            'latitude' => (float) $response['latitude'],
            'longitude' => (float) $response['longitude'],
            'temperature' => (float) $weather['temperature'],
            'unit' => $units === 'fahrenheit' ? '°F' : '°C',
            'conditions' => $conditions,
            'weatherCode' => $weatherCode,
            'windSpeed' => (float) $weather['windspeed'],
            'windUnit' => $units === 'fahrenheit' ? 'mph' : 'km/h',
            'windDirection' => (int) $weather['winddirection'],
            'isDay' => (bool) $weather['is_day'],
            'time' => (string) $weather['time'],
        ];
    }

    /**
     * Get weather forecast for a location
     *
     * @param array{latitude: float, longitude: float, days?: int, units?: string} $input
     * @return array{latitude: float, longitude: float, unit: string, forecast: array<int, array{date: string, high: float, low: float, conditions: string, weatherCode: int}>}
     */
    #[Action(
        name: 'forecast',
        description: 'Get weather forecast for a location',
        attribute: 'execute',
        subject: 'weather',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'latitude' => [
                    'type' => 'number',
                    'description' => 'Latitude coordinate',
                    'examples' => [52.52],
                ],
                'longitude' => [
                    'type' => 'number',
                    'description' => 'Longitude coordinate',
                    'examples' => [13.41],
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Number of days to forecast (1-16)',
                    'default' => 7,
                    'examples' => [7],
                ],
                'units' => [
                    'type' => 'string',
                    'description' => 'Temperature units',
                    'default' => 'celsius',
                    'examples' => ['celsius'],
                ],
            ],
            'required' => ['latitude', 'longitude'],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'latitude' => ['type' => 'number'],
                'longitude' => ['type' => 'number'],
                'unit' => ['type' => 'string'],
                'forecast' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string'],
                            'high' => ['type' => 'number'],
                            'low' => ['type' => 'number'],
                            'conditions' => ['type' => 'string'],
                            'weatherCode' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'required' => ['latitude', 'longitude', 'unit', 'forecast'],
        ]
    )]
    public function forecast(array $input, HostInterface $host): array
    {
        $latitude = (float) $input['latitude'];
        $longitude = (float) $input['longitude'];
        $days = min(16, max(1, (int) ($input['days'] ?? 7)));
        $units = (string) ($input['units'] ?? 'celsius');

        $host->info("Fetching {$days}-day forecast for coordinates: {$latitude}, {$longitude}");

        // Build API URL
        $params = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'daily' => 'temperature_2m_max,temperature_2m_min,weathercode',
            'forecast_days' => $days,
            'timezone' => 'auto',
        ];

        if ($units === 'fahrenheit') {
            $params['temperature_unit'] = 'fahrenheit';
        }

        $url = self::API_BASE . '?' . http_build_query($params);
        $host->debug("API URL: {$url}");

        $response = $this->fetchJson($url);

        if (!isset($response['daily'])) {
            throw new \RuntimeException('Invalid API response: missing daily forecast data');
        }

        $daily = $response['daily'];
        $forecast = [];

        $dates = $daily['time'] ?? [];
        $highs = $daily['temperature_2m_max'] ?? [];
        $lows = $daily['temperature_2m_min'] ?? [];
        $codes = $daily['weathercode'] ?? [];

        for ($i = 0; $i < count($dates); $i++) {
            $host->progress($i + 1, count($dates), "Processing day " . ($i + 1));

            $weatherCode = (int) ($codes[$i] ?? 0);
            $conditions = self::WEATHER_CODES[$weatherCode] ?? 'Unknown';

            $forecast[] = [
                'date' => (string) $dates[$i],
                'high' => (float) ($highs[$i] ?? 0),
                'low' => (float) ($lows[$i] ?? 0),
                'conditions' => $conditions,
                'weatherCode' => $weatherCode,
            ];
        }

        $host->info("Forecast generated successfully");

        return [
            'latitude' => (float) $response['latitude'],
            'longitude' => (float) $response['longitude'],
            'unit' => $units === 'fahrenheit' ? '°F' : '°C',
            'forecast' => $forecast,
        ];
    }

    /**
     * Fetch JSON from URL
     *
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new \RuntimeException("Failed to fetch weather data from API");
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON response from weather API");
        }

        if (isset($data['error']) && $data['error'] === true) {
            $reason = $data['reason'] ?? 'Unknown error';
            throw new \RuntimeException("Weather API error: {$reason}");
        }

        return $data;
    }
}
