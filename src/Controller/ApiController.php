<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Controller;

use LinkORB\Bundle\ActionBundle\Service\ActionApprovalService;
use LinkORB\Bundle\ActionBundle\Service\ActionService;
use LinkORB\Component\Action\Host\CallbackHost;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED')]
#[Route('/api/v1/action/actions')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly ActionService $actionService,
        private readonly ActionApprovalService $approvalService,
    ) {
    }

    #[Route('', name: 'linkorb_bundle__action_api_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $actions = $this->actionService->getActions();
        $data = [];

        foreach ($actions as $action) {
            $data[] = [
                'name' => $action->getName(),
                'description' => $action->getDescription(),
                'inputSchema' => $action->getInputSchema(),
                'outputSchema' => $action->getOutputSchema(),
            ];
        }

        return new JsonResponse([
            'actions' => $data,
        ]);
    }

    #[Route('/{name}', name: 'linkorb_bundle__action_api_view', methods: ['GET'], requirements: ['name' => '.+'])]
    public function view(string $name): JsonResponse
    {
        $name = urldecode($name);

        if (!$this->actionService->hasAction($name)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Action not found: {$name}",
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $action = $this->actionService->getAction($name);

        return new JsonResponse([
            'name' => $action->getName(),
            'description' => $action->getDescription(),
            'inputSchema' => $action->getInputSchema(),
            'outputSchema' => $action->getOutputSchema(),
        ]);
    }

    #[Route('/{name}/execute', name: 'linkorb_bundle__action_api_execute', methods: ['POST'], requirements: ['name' => '.+'])]
    public function execute(Request $request, string $name): Response
    {
        $name = urldecode($name);

        if (!$this->actionService->hasAction($name)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Action not found: {$name}",
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $action = $this->actionService->getAction($name);

        // Enforce per-action authorization gate
        $definition = $action->getDefinition();
        $attribute = $definition->getMetadataValue('attribute');
        $subject = $definition->getMetadataValue('subject');

        if ($attribute === null || $subject === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'AUTHORIZATION_NOT_DECLARED',
                    'message' => "Action '{$name}' does not declare permission requirements",
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $this->denyAccessUnlessGranted($attribute, $subject);

        /** @var string $content */
        $content = $request->getContent();

        /** @var array<string, mixed>|null $input */
        $input = json_decode($content, true);

        if ($input === null && $content !== '' && $content !== '{}') {
            return new JsonResponse([
                'error' => [
                    'code' => 'INVALID_INPUT',
                    'message' => 'Invalid JSON input: ' . json_last_error_msg(),
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $resolvedInput = $input ?? [];

        /** @var \Symfony\Component\Security\Core\User\UserInterface $user */
        $user = $this->getUser();
        $caller = $user->getUserIdentifier();
        $webhookUrl = $request->headers->get('X-Approval-Webhook');

        // Approval gate: check pre-approval rules
        if ($this->approvalService->isPreApproved($caller, $name, $resolvedInput)) {
            return $this->executeAction($request, $action, $resolvedInput);
        }

        // Approval required
        $approval = $this->approvalService->createApproval($name, $resolvedInput, $caller, $webhookUrl);

        // Streaming (sync): wait for approval decision inline
        $acceptHeader = (string) $request->headers->get('Accept', '');
        if (str_contains($acceptHeader, 'application/x-ndjson') || $request->query->has('stream')) {
            return $this->executeStreamingWithApproval($action, $approval);
        }

        // Non-streaming (async): return 202 with approval XUID for polling
        return new JsonResponse([
            'status' => 'approval_required',
            'approval_xuid' => $approval->getXuid(),
            'action' => $name,
            'poll_url' => "/api/v1/action/approvals/{$approval->getXuid()}",
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Execute action directly (pre-approved path), with streaming or non-streaming.
     *
     * @param array<string, mixed> $input
     */
    private function executeAction(Request $request, \LinkORB\Component\Action\Action $action, array $input): Response
    {
        $acceptHeader = (string) $request->headers->get('Accept', '');
        if (str_contains($acceptHeader, 'application/x-ndjson') || $request->query->has('stream')) {
            return $this->executeStreaming($action, $input);
        }

        $result = $this->actionService->executeAction($action->getName(), $input, null, 'rest');

        $response = [
            'success' => $result->isSuccess(),
            'data' => $result->getData(),
        ];

        if ($result->getError() !== null) {
            $response['error'] = $result->getError();
        }

        return new JsonResponse(
            $response,
            $result->isSuccess() ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Execute action with streaming response (pre-approved, no approval wait).
     *
     * @param array<string, mixed> $input
     */
    private function executeStreaming(\LinkORB\Component\Action\Action $action, array $input): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($action, $input): void {
            if (ob_get_level()) {
                ob_end_flush();
            }

            $host = new CallbackHost(function ($message) {
                echo $message->toJson() . "\n";
                flush();
            });

            $result = $this->actionService->executeAction($action->getName(), $input, $host, 'rest');

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

    /**
     * Streaming execution with approval wait: emits approval_required, waits for decision,
     * then executes and streams the result.
     */
    private function executeStreamingWithApproval(
        \LinkORB\Component\Action\Action $action,
        \LinkORB\Bundle\ActionBundle\Entity\ActionApproval $approval,
    ): StreamedResponse {
        $response = new StreamedResponse(function () use ($action, $approval): void {
            if (ob_get_level()) {
                ob_end_flush();
            }

            $host = new CallbackHost(function ($message) {
                echo $message->toJson() . "\n";
                flush();
            });

            $approval = $this->approvalService->waitForDecision($approval, $host);

            if ($approval->isApproved()) {
                $result = $this->approvalService->executeApproved($approval, $host);

                if (!$result->isSuccess()) {
                    echo json_encode([
                        'type' => 'error',
                        'message' => $result->getError(),
                    ]) . "\n";
                }
            }

            flush();
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }
}
