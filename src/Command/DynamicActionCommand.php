<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Command;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use LinkORB\Component\Action\Action;
use LinkORB\Component\Action\Host\CallbackHost;
use LinkORB\Component\Action\Message\ErrorMessage;
use LinkORB\Component\Action\Message\LogMessage;
use LinkORB\Component\Action\Message\OutputMessage;
use LinkORB\Component\Action\Message\ProgressMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dynamic command generated from an Action definition
 */
class DynamicActionCommand extends Command
{
    private Action $action;
    private ActionService $actionService;

    public function __construct(Action $action, string $commandName, ActionService $actionService)
    {
        $this->action = $action;
        $this->actionService = $actionService;

        parent::__construct($commandName);
    }

    protected function configure(): void
    {
        $this->setDescription($this->action->getDescription());

        $inputSchema = $this->action->getInputSchema();

        // Add options based on input schema properties
        $properties = $inputSchema['properties'] ?? [];

        /** @var array<string, mixed> $propertyDef */
        foreach ($properties as $propertyName => $propertyDef) {
            $description = $this->getPropertyDescription($propertyDef);
            $default = $this->getPropertyDefault($propertyDef);
            $required = $this->isPropertyRequired($propertyName, $inputSchema);

            // Use VALUE_REQUIRED only if property is required and has no default
            $mode = InputOption::VALUE_REQUIRED;
            if (!$required || $default !== null) {
                $mode = InputOption::VALUE_OPTIONAL;
            }

            $this->addOption(
                $propertyName,
                null,
                $mode,
                $description,
                $default
            );
        }

        // Add output format option
        $this->addOption(
            'output-format',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output format: pretty (default) or ndjson',
            'pretty'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $outputFormat */
        $outputFormat = $input->getOption('output-format');
        $ndjson = $outputFormat === 'ndjson';

        // Build input from options
        $actionInput = $this->buildInputFromOptions($input);

        if (!$ndjson) {
            $io->title("Executing: {$this->action->getName()}");
            $io->writeln("<info>Description:</info> {$this->action->getDescription()}");
            $io->newLine();
        }

        // Create host based on output mode
        $progressBar = null;
        $host = new CallbackHost(function ($message) use ($output, $io, $ndjson, &$progressBar) {
            if ($ndjson) {
                $output->writeln($message->toJson());

                return;
            }

            if ($message instanceof LogMessage) {
                $level = $message->getLevel();
                $text = $message->getMessage();

                match ($level) {
                    'error' => $io->error($text),
                    'warning' => $io->warning($text),
                    'debug' => $output->isVerbose() ? $io->comment("[DEBUG] {$text}") : null,
                    default => $io->writeln("  <info>→</info> {$text}"),
                };
            } elseif ($message instanceof ProgressMessage) {
                if ($progressBar === null && $message->getTotal() > 0) {
                    $progressBar = new ProgressBar($output, $message->getTotal());
                    $progressBar->start();
                }

                if ($progressBar !== null) {
                    $progressBar->setProgress($message->getCurrent());
                    if ($message->getMessage() !== '') {
                        $progressBar->setMessage($message->getMessage());
                    }

                    if ($message->getCurrent() >= $message->getTotal()) {
                        $progressBar->finish();
                        $io->newLine(2);
                        $progressBar = null;
                    }
                }
            } elseif ($message instanceof OutputMessage) {
                $io->newLine();
                $io->section('Output');
                $io->writeln(json_encode($message->getData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            } elseif ($message instanceof ErrorMessage) {
                $io->error($message->getMessage());
            }
        });

        // Execute action
        $result = $this->actionService->executeAction($this->action->getName(), $actionInput, $host, 'console');

        if (!$ndjson) {
            $io->newLine();

            if ($result->isSuccess()) {
                $io->success('Action completed successfully');
            } else {
                $io->error('Action failed: ' . ($result->getError() ?? 'Unknown error'));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Build action input array from command options
     *
     * @return array<string, mixed>
     */
    private function buildInputFromOptions(InputInterface $input): array
    {
        $actionInput = [];
        $inputSchema = $this->action->getInputSchema();
        $properties = $inputSchema['properties'] ?? [];

        foreach (array_keys($properties) as $propertyName) {
            $value = $input->getOption($propertyName);

            if ($value !== null) {
                // Try to parse JSON for complex types
                $actionInput[$propertyName] = $this->parseOptionValue($value, $properties[$propertyName]);
            }
        }

        return $actionInput;
    }

    /**
     * Parse an option value based on its schema type
     *
     * @param array<string, mixed> $propertyDef
     */
    private function parseOptionValue(mixed $value, array $propertyDef): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $type = $propertyDef['type'] ?? 'string';

        return match ($type) {
            'integer' => (int) $value,
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value === 'true',
            'array', 'object' => $this->tryJsonDecode($value),
            default => $value,
        };
    }

    /**
     * Try to decode a JSON string, return original on failure
     */
    private function tryJsonDecode(string $value): mixed
    {
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Get description from property definition
     *
     * @param array<string, mixed> $propertyDef
     */
    private function getPropertyDescription(array $propertyDef): string
    {
        $description = (string) ($propertyDef['description'] ?? '');

        // Add type info
        $type = $propertyDef['type'] ?? 'string';
        if ($description !== '') {
            $description .= " [{$type}]";
        } else {
            $description = "({$type})";
        }

        // Add examples if available
        $examples = $propertyDef['examples'] ?? [];
        if (!empty($examples)) {
            $exampleStr = implode(', ', array_map(
                fn ($ex) => is_string($ex) ? $ex : json_encode($ex),
                $examples
            ));
            $description .= " (e.g., {$exampleStr})";
        }

        return $description;
    }

    /**
     * Get default value from property definition
     *
     * @param array<string, mixed> $propertyDef
     */
    private function getPropertyDefault(array $propertyDef): mixed
    {
        if (array_key_exists('default', $propertyDef)) {
            $default = $propertyDef['default'];
            // Convert non-string defaults to string for CLI
            if (!is_string($default) && !is_null($default)) {
                return json_encode($default);
            }

            return $default;
        }

        return null;
    }

    /**
     * Check if a property is required
     *
     * @param array<string, mixed> $schema
     */
    private function isPropertyRequired(string $propertyName, array $schema): bool
    {
        $required = $schema['required'] ?? [];

        return in_array($propertyName, $required, true);
    }
}


