<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\ActionPack;

use LinkORB\Component\Action\Attribute\Action;
use LinkORB\Component\Action\Attribute\ActionPack;
use LinkORB\Component\Action\Host\HostInterface;

/**
 * Demo ActionPack for testing and demonstration purposes
 */
#[ActionPack(
    name: 'demo',
    description: 'Demo actions for testing and demonstration'
)]
class DemoActionPack
{
    /**
     * A slow action that outputs log lines with random delays (no progress bar)
     *
     * @param array{count?: int, minDelay?: float, maxDelay?: float} $input
     * @return array{totalTime: float, linesEmitted: int}
     */
    #[Action(
        name: 'slow',
        description: 'Output log lines with random delays (useful for testing streaming logs)',
        attribute: 'execute',
        subject: 'demo-admin',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'count' => [
                    'type' => 'integer',
                    'description' => 'Number of log lines to output',
                    'default' => 10,
                    'examples' => [10],
                ],
                'minDelay' => [
                    'type' => 'number',
                    'description' => 'Minimum delay between lines in seconds',
                    'default' => 1,
                    'examples' => [1],
                ],
                'maxDelay' => [
                    'type' => 'number',
                    'description' => 'Maximum delay between lines in seconds',
                    'default' => 5,
                    'examples' => [5],
                ],
            ],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'totalTime' => ['type' => 'number', 'description' => 'Total execution time in seconds'],
                'linesEmitted' => ['type' => 'integer', 'description' => 'Number of lines emitted'],
            ],
            'required' => ['totalTime', 'linesEmitted'],
        ]
    )]
    public function slow(array $input, HostInterface $host): array
    {
        $count = (int) ($input['count'] ?? 10);
        $minDelay = (float) ($input['minDelay'] ?? 1);
        $maxDelay = (float) ($input['maxDelay'] ?? 5);

        $host->info("Starting slow action with {$count} lines, delays between {$minDelay}s and {$maxDelay}s");

        $startTime = microtime(true);

        $messages = [
            'Processing data batch...',
            'Analyzing results...',
            'Computing metrics...',
            'Validating output...',
            'Aggregating information...',
            'Running calculations...',
            'Fetching resources...',
            'Transforming data...',
        ];

        for ($i = 1; $i <= $count; $i++) {
            $host->info($messages[array_rand($messages)] . " (line {$i}/{$count})");

            // Random delay between min and max
            $delay = $minDelay + (mt_rand() / mt_getrandmax()) * ($maxDelay - $minDelay);
            usleep((int) ($delay * 1000000));
        }

        $totalTime = round(microtime(true) - $startTime, 2);
        $host->info("Completed in {$totalTime} seconds");

        return [
            'totalTime' => $totalTime,
            'linesEmitted' => $count,
        ];
    }

    /**
     * An action that outputs progress updates with random delays
     *
     * @param array{count?: int, minDelay?: float, maxDelay?: float} $input
     * @return array{totalTime: float, stepsCompleted: int}
     */
    #[Action(
        name: 'progress',
        description: 'Output progress updates with random delays (useful for testing progress bars)',
        attribute: 'execute',
        subject: 'demo',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'count' => [
                    'type' => 'integer',
                    'description' => 'Number of progress steps',
                    'default' => 10,
                    'examples' => [10],
                ],
                'minDelay' => [
                    'type' => 'number',
                    'description' => 'Minimum delay between steps in seconds',
                    'default' => 1,
                    'examples' => [1],
                ],
                'maxDelay' => [
                    'type' => 'number',
                    'description' => 'Maximum delay between steps in seconds',
                    'default' => 5,
                    'examples' => [5],
                ],
            ],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'totalTime' => ['type' => 'number', 'description' => 'Total execution time in seconds'],
                'stepsCompleted' => ['type' => 'integer', 'description' => 'Number of steps completed'],
            ],
            'required' => ['totalTime', 'stepsCompleted'],
        ]
    )]
    public function progress(array $input, HostInterface $host): array
    {
        $count = (int) ($input['count'] ?? 10);
        $minDelay = (float) ($input['minDelay'] ?? 1);
        $maxDelay = (float) ($input['maxDelay'] ?? 5);

        $host->info("Starting progress action with {$count} steps");

        $startTime = microtime(true);

        $tasks = [
            'Downloading files',
            'Processing data',
            'Analyzing results',
            'Building index',
            'Optimizing output',
            'Validating data',
            'Compressing files',
            'Uploading results',
        ];

        for ($i = 1; $i <= $count; $i++) {
            $task = $tasks[($i - 1) % count($tasks)];
            $host->progress($i, $count, "{$task}... (step {$i}/{$count})");

            // Random delay between min and max
            $delay = $minDelay + (mt_rand() / mt_getrandmax()) * ($maxDelay - $minDelay);
            usleep((int) ($delay * 1000000));
        }

        $totalTime = round(microtime(true) - $startTime, 2);

        return [
            'totalTime' => $totalTime,
            'stepsCompleted' => $count,
        ];
    }

    /**
     * Simple greeting action
     *
     * @param array{name: string} $input
     * @return array{message: string}
     */
    #[Action(
        name: 'greet',
        description: 'Greet a user by name',
        attribute: 'execute',
        subject: 'demo',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name to greet',
                    'examples' => ['World'],
                ],
            ],
            'required' => ['name'],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Greeting message'],
            ],
            'required' => ['message'],
        ]
    )]
    public function greet(array $input, HostInterface $host): array
    {
        $name = $input['name'];

        $host->info("Preparing greeting for: {$name}");

        return [
            'message' => "Hello, {$name}!",
        ];
    }

    /**
     * Echo action - returns the input as output
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    #[Action(
        name: 'echo',
        description: 'Echo back the input as output',
        attribute: 'execute',
        subject: 'demo',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'description' => 'Any data to echo back',
                    'examples' => [['key' => 'value']],
                ],
            ],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'echoed' => ['description' => 'The echoed data'],
            ],
        ]
    )]
    public function echo(array $input, HostInterface $host): array
    {
        $host->debug('Echoing input data');

        return [
            'echoed' => $input['data'] ?? $input,
        ];
    }

    /**
     * Action that always fails - useful for testing error handling
     *
     * @param array{message?: string} $input
     * @return array<string, mixed>
     */
    #[Action(
        name: 'fail',
        description: 'An action that always fails (for testing error handling)',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Custom error message',
                    'default' => 'This action always fails',
                    'examples' => ['Custom error message'],
                ],
            ],
        ],
        outputSchema: [
            'type' => 'object',
        ]
    )]
    public function fail(array $input, HostInterface $host): array
    {
        $message = (string) ($input['message'] ?? 'This action always fails');

        $host->error("About to fail with: {$message}");

        throw new \RuntimeException($message);
    }
}

