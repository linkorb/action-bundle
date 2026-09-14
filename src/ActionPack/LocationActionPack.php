<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\ActionPack;

use LinkORB\Component\Action\Attribute\Action;
use LinkORB\Component\Action\Attribute\ActionPack;
use LinkORB\Component\Action\Host\HostInterface;

/**
 * ActionPack for location-related operations
 */
#[ActionPack(
    name: 'location',
    description: 'Location-related actions for geocoding and reverse geocoding'
)]
class LocationActionPack
{
    /**
     * Convert a location name to latitude/longitude coordinates
     *
     * Note: This is a demo implementation using mock data.
     * In production, you would integrate with a real geocoding API (Google Maps, OpenStreetMap Nominatim, etc.)
     *
     * @param array{query: string} $input
     * @return array{name: string, latitude: float, longitude: float, country?: string, displayName: string}
     */
    #[Action(
        name: 'geocode',
        description: 'Convert a location name to latitude/longitude coordinates',
        attribute: 'execute',
        subject: 'location',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Location name to geocode (city, address, landmark)',
                    'examples' => ['Amsterdam', 'Eiffel Tower', 'New York City'],
                ],
            ],
            'required' => ['query'],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Location name'],
                'latitude' => ['type' => 'number', 'description' => 'Latitude coordinate'],
                'longitude' => ['type' => 'number', 'description' => 'Longitude coordinate'],
                'country' => ['type' => 'string', 'description' => 'Country name'],
                'displayName' => ['type' => 'string', 'description' => 'Full display name'],
            ],
            'required' => ['name', 'latitude', 'longitude', 'displayName'],
        ]
    )]
    public function geocode(array $input, HostInterface $host): array
    {
        $query = $input['query'];

        $host->info("Geocoding location: {$query}");

        // Mock data for demonstration
        // In production, call a real geocoding API
        /** @var array<string, array{latitude: float, longitude: float, country: string, displayName: string}> $locations */
        $locations = [
            'amsterdam' => [
                'latitude' => 52.3676,
                'longitude' => 4.9041,
                'country' => 'Netherlands',
                'displayName' => 'Amsterdam, North Holland, Netherlands',
            ],
            'paris' => [
                'latitude' => 48.8566,
                'longitude' => 2.3522,
                'country' => 'France',
                'displayName' => 'Paris, Île-de-France, France',
            ],
            'london' => [
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'country' => 'United Kingdom',
                'displayName' => 'London, England, United Kingdom',
            ],
            'new york' => [
                'latitude' => 40.7128,
                'longitude' => -74.0060,
                'country' => 'United States',
                'displayName' => 'New York City, NY, United States',
            ],
            'new york city' => [
                'latitude' => 40.7128,
                'longitude' => -74.0060,
                'country' => 'United States',
                'displayName' => 'New York City, NY, United States',
            ],
            'tokyo' => [
                'latitude' => 35.6762,
                'longitude' => 139.6503,
                'country' => 'Japan',
                'displayName' => 'Tokyo, Japan',
            ],
            'berlin' => [
                'latitude' => 52.5200,
                'longitude' => 13.4050,
                'country' => 'Germany',
                'displayName' => 'Berlin, Germany',
            ],
            'eiffel tower' => [
                'latitude' => 48.8584,
                'longitude' => 2.2945,
                'country' => 'France',
                'displayName' => 'Eiffel Tower, Champ de Mars, Paris, France',
            ],
        ];

        $normalizedQuery = strtolower(trim($query));
        $location = $locations[$normalizedQuery] ?? null;

        if ($location === null) {
            $host->warning("Location not found in mock database, generating random coordinates");

            // Generate mock coordinates for unknown locations
            $location = [
                'latitude' => round(mt_rand(-9000, 9000) / 100, 4),
                'longitude' => round(mt_rand(-18000, 18000) / 100, 4),
                'country' => 'Unknown',
                'displayName' => ucfirst($query),
            ];
        }

        $host->info("Found coordinates: {$location['latitude']}, {$location['longitude']}");

        return [
            'name' => ucfirst($query),
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'country' => $location['country'],
            'displayName' => $location['displayName'],
        ];
    }

    /**
     * Convert latitude/longitude to a location name (reverse geocoding)
     *
     * @param array{latitude: float, longitude: float} $input
     * @return array{name: string, displayName: string, country?: string}
     */
    #[Action(
        name: 'reverseGeocode',
        description: 'Convert latitude/longitude coordinates to a location name',
        attribute: 'execute',
        subject: 'location',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'latitude' => [
                    'type' => 'number',
                    'description' => 'Latitude coordinate',
                    'examples' => [52.3676],
                ],
                'longitude' => [
                    'type' => 'number',
                    'description' => 'Longitude coordinate',
                    'examples' => [4.9041],
                ],
            ],
            'required' => ['latitude', 'longitude'],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Location name'],
                'displayName' => ['type' => 'string', 'description' => 'Full display name'],
                'country' => ['type' => 'string', 'description' => 'Country name'],
            ],
            'required' => ['name', 'displayName'],
        ]
    )]
    public function reverseGeocode(array $input, HostInterface $host): array
    {
        $latitude = $input['latitude'];
        $longitude = $input['longitude'];

        $host->info("Reverse geocoding: {$latitude}, {$longitude}");

        // Mock implementation
        // Find closest known location
        /** @var array<string, array{latitude: float, longitude: float, name: string, displayName: string, country: string}> $locations */
        $locations = [
            'amsterdam' => [
                'latitude' => 52.3676,
                'longitude' => 4.9041,
                'name' => 'Amsterdam',
                'displayName' => 'Amsterdam, North Holland, Netherlands',
                'country' => 'Netherlands',
            ],
            'paris' => [
                'latitude' => 48.8566,
                'longitude' => 2.3522,
                'name' => 'Paris',
                'displayName' => 'Paris, Île-de-France, France',
                'country' => 'France',
            ],
        ];

        $closest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($locations as $location) {
            $distance = sqrt(
                pow($latitude - $location['latitude'], 2) +
                pow($longitude - $location['longitude'], 2)
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closest = $location;
            }
        }

        if ($closest !== null && $minDistance < 1) {
            $host->info("Found nearby location: {$closest['name']}");

            return [
                'name' => $closest['name'],
                'displayName' => $closest['displayName'],
                'country' => $closest['country'],
            ];
        }

        $host->warning('No known location found nearby');

        return [
            'name' => "Location at {$latitude}, {$longitude}",
            'displayName' => "Unknown location at coordinates {$latitude}, {$longitude}",
        ];
    }
}

