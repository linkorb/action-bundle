<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Controller;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use LinkORB\Component\Action\Host\BufferedHost;
use LinkORB\Component\Action\Host\CallbackHost;
use LinkORB\Component\Action\Message\OutputMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/action/actions')]
class ActionController extends AbstractController
{
    public function __construct(
        private readonly ActionService $actionService,
    ) {
    }

    #[Route('', name: 'linkorb_bundle__action_index', methods: ['GET'])]
    public function index(): Response
    {
        $groupedActions = $this->actionService->getActionsGroupedByPack();

        return $this->render('@LinkORBAction/action/index.html.twig', [
            'groupedActions' => $groupedActions,
        ]);
    }

    #[Route('/stream/{name}', name: 'linkorb_bundle__action_execute_stream', methods: ['GET', 'POST'], requirements: ['name' => '.+'])]
    public function executeStream(Request $request, string $name): StreamedResponse|Response
    {
        // GET requests should redirect to the action view page
        if ($request->isMethod('GET')) {
            return $this->redirectToRoute('linkorb_bundle__action_view', ['name' => $name]);
        }

        $name = urldecode($name);

        if (!$this->actionService->hasAction($name)) {
            return new StreamedResponse(function () use ($name): void {
                echo json_encode(['type' => 'error', 'message' => "Action not found: {$name}"]) . "\n";
            }, Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'application/x-ndjson',
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-cache',
            ]);
        }

        $action = $this->actionService->getAction($name);

        /** @var string $content */
        $content = $request->getContent();

        /** @var array<string, mixed>|null $input */
        $input = json_decode($content, true);

        if ($input === null && $content !== '' && $content !== '{}') {
            return new StreamedResponse(function (): void {
                echo json_encode(['type' => 'error', 'message' => 'Invalid JSON input']) . "\n";
            }, Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'application/x-ndjson',
            ]);
        }

        $response = new StreamedResponse(function () use ($action, $input): void {
            // Disable output buffering for immediate streaming
            if (ob_get_level()) {
                ob_end_flush();
            }

            $host = new CallbackHost(function ($message) {
                echo $message->toJson() . "\n";
                flush();
            });

            $result = $this->actionService->executeAction($action->getName(), $input ?? [], $host, 'web');

            // Emit final result if not already emitted via output message
            if (!$result->isSuccess()) {
                echo json_encode([
                    'type' => 'error',
                    'message' => $result->getError(),
                ]) . "\n";
            }

            flush();
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }

    #[Route('/{name}', name: 'linkorb_bundle__action_view', methods: ['GET', 'POST'], requirements: ['name' => '.+'])]
    public function view(Request $request, string $name): Response
    {
        $name = urldecode($name);

        if (!$this->actionService->hasAction($name)) {
            throw $this->createNotFoundException("Action not found: {$name}");
        }

        $action = $this->actionService->getAction($name);

        // Get default input from schema examples
        /** @var string|null $json */
        $json = $request->request->get('json');

        if (!$json) {
            $inputs = $this->getDefaultInputFromSchema($action->getInputSchema());
            $json = json_encode($inputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $output = null;
        $messages = [];

        if ($request->isMethod('POST') && is_string($json)) {
            /** @var array<string, mixed>|null $input */
            $input = json_decode($json, true);

            if ($input === null && $json !== '{}') {
                $output = 'Invalid JSON input: ' . json_last_error_msg();
            } else {
                $host = new BufferedHost();
                $result = $this->actionService->executeAction($name, $input ?? [], $host, 'web');
                $messages = $host->getMessages();
                $output = json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return $this->render('@LinkORBAction/action/view.html.twig', [
            'action' => $action,
            'json' => $json,
            'output' => $output,
            'messages' => $messages,
        ]);
    }

    /**
     * Get default input values from schema examples
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function getDefaultInputFromSchema(array $schema): array
    {
        $inputs = [];

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'] ?? [];

        foreach ($properties as $propName => $propSchema) {
            $value = null;

            // Try to get from examples
            /** @var array<int, mixed> $examples */
            $examples = $propSchema['examples'] ?? [];
            if (!empty($examples)) {
                $value = $examples[0];
            }

            // Try to get from default
            if ($value === null && isset($propSchema['default'])) {
                $value = $propSchema['default'];
            }

            // Generate placeholder based on type
            if ($value === null) {
                /** @var string|null $type */
                $type = $propSchema['type'] ?? null;
                $value = match ($type) {
                    'string' => '',
                    'number', 'integer' => 0,
                    'boolean' => false,
                    'array' => [],
                    'object' => [],
                    default => null,
                };
            }

            if ($value !== null) {
                $inputs[$propName] = $value;
            }
        }

        return $inputs;
    }
}

