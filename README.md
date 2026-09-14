> This is a read-only repository used to release the sub-tree. Any issues and pull requests should be forwarded to the upstream [Action repository](https://github.com/linkorb/action).

# Action Bundle

Symfony bundle for managing and executing FaaS-like Actions. Provides a Doctrine-backed approval flow, web UI, REST API, MCP endpoint, and console commands on top of `linkorb/action-component`.

This package lives in the [Action](https://github.com/linkorb/action) repository and is required by the standalone app via a Composer path repository.

## Register an ActionPack

```yaml
# config/services.yaml
services:
    App\ActionPack\MyActionPack:
        tags: ['action.pack']
```

```php
use LinkORB\Component\Action\Attribute\Action;
use LinkORB\Component\Action\Attribute\ActionPack;
use LinkORB\Component\Action\Host\HostInterface;

#[ActionPack(name: 'my', description: 'My custom actions')]
class MyActionPack
{
    #[Action(
        name: 'hello',
        description: 'Say hello',
        inputSchema: [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string']],
        ],
    )]
    public function hello(array $input, HostInterface $host): array
    {
        $host->log('info', 'Saying hello to ' . $input['name']);

        return ['message' => 'Hello, ' . $input['name'] . '!'];
    }
}
```

## Routes

| Surface | Path |
|---|---|
| Web UI | `/action/actions` |
| Approvals | `/action/approvals` |
| REST | `/api/v1/action/actions` |
| MCP | `/api/action/mcp` |

```bash
bin/console action:run demo.greet --input='{"name":"World"}'
bin/console action:list
```
