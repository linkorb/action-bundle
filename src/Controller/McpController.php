<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Controller;

use LinkORB\Bundle\ActionBundle\Service\ActionService;
use LinkORB\Component\Action\Host\BufferedHost;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * MCP (Model Context Protocol) server controller
 *
 * Exposes all registered actions as MCP tools via HTTP transport.
 */
#[IsGranted('IS_AUTHENTICATED')]
class McpController extends AbstractController
{
    public function __construct(
        private readonly ActionService $actionService,
        private readonly string $cacheDir,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/action/mcp', name: 'linkorb_bundle__action_mcp', methods: ['GET', 'POST', 'DELETE', 'OPTIONS'])]
    public function mcp(Request $request): Response
    {
        $manager = $this->actionService->getManager();

        // Use file-based session store to persist sessions between HTTP requests
        $sessionDir = $this->cacheDir . '/mcp_sessions';
        $sessionStore = new FileSessionStore($sessionDir, 3600);

        // Build MCP server with tools from ActionManager
        $serverBuilder = Server::builder()
            ->setServerInfo('Nebula Action Server', '1.0.0')
            ->setSession($sessionStore);

        // Register each action as an MCP tool
        foreach ($manager->getActions() as $action) {
            $actionName = $action->getName();
            $inputSchema = $action->getInputSchema();
            $definition = $action->getDefinition();

            $authAttribute = $definition->getMetadataValue('attribute');
            $authSubject = $definition->getMetadataValue('subject');
            $authChecker = $this->authorizationChecker;
            $actionService = $this->actionService;

            // Create a closure that captures all arguments using RequestContext
            // MCP's ReferenceHandler injects RequestContext which contains the original request
            $callable = function (RequestContext $context) use ($actionName, $authAttribute, $authSubject, $authChecker, $actionService): array {
                // Enforce per-action authorization gate
                if ($authAttribute === null || $authSubject === null) {
                    $actionService->logActionOutcome($actionName, 'mcp', 'refused_undeclared_permissions');

                    return [
                        'success' => false,
                        'data' => [],
                        'error' => "Action '{$actionName}' does not declare permission requirements",
                        'messages' => [],
                    ];
                }

                if (!$authChecker->isGranted($authAttribute, $authSubject)) {
                    $actionService->logActionOutcome($actionName, 'mcp', 'denied');

                    return [
                        'success' => false,
                        'data' => [],
                        'error' => 'Access denied',
                        'messages' => [],
                    ];
                }

                // Get arguments from the original MCP request
                $request = $context->getRequest();
                $arguments = [];
                if ($request instanceof CallToolRequest) {
                    $arguments = $request->arguments ?? [];
                }

                $host = new BufferedHost();
                $result = $actionService->executeAction($actionName, $arguments, $host, 'mcp');

                return [
                    'success' => $result->isSuccess(),
                    'data' => $result->getData(),
                    'error' => $result->getError(),
                    'messages' => array_map(
                        fn ($msg) => $msg->toArray(),
                        $host->getMessages()
                    ),
                ];
            };

            // Register tool with MCP server
            // MCP tool name replaces dots with underscores for compatibility
            $toolName = str_replace('.', '_', $actionName);

            $serverBuilder->addTool(
                handler: $callable,
                name: $toolName,
                description: $action->getDescription(),
                inputSchema: $this->convertToMcpSchema($inputSchema)
            );
        }

        $server = $serverBuilder->build();

        // Convert Symfony request to PSR-7
        $psr17Factory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        $psrRequest = $psrHttpFactory->createRequest($request);

        // Create HTTP transport and run server
        $transport = new StreamableHttpTransport(
            $psrRequest,
            $psr17Factory,
            $psr17Factory
        );

        $psrResponse = $server->run($transport);

        // Convert PSR-7 response back to Symfony
        $httpFoundationFactory = new HttpFoundationFactory();

        return $httpFoundationFactory->createResponse($psrResponse);
    }

    /**
     * Convert JSON Schema to MCP tool input schema format
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function convertToMcpSchema(array $schema): array
    {
        // MCP uses standard JSON Schema, so we can mostly pass through
        // but ensure required fields are present
        $mcpSchema = [
            'type' => $schema['type'] ?? 'object',
        ];

        // Empty/absent properties must serialize as {} not []; MCP clients reject an array here.
        $mcpSchema['properties'] = (isset($schema['properties']) && $schema['properties'] !== [])
            ? $schema['properties']
            : new \stdClass();

        if (isset($schema['required'])) {
            $mcpSchema['required'] = $schema['required'];
        }

        if (isset($schema['description'])) {
            $mcpSchema['description'] = $schema['description'];
        }

        return $mcpSchema;
    }
}
