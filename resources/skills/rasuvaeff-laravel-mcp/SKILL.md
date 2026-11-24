---
name: rasuvaeff-laravel-mcp
description: >-
  Serve Laravel services as MCP tools/resources/prompts with
  rasuvaeff/laravel-mcp (McpServiceProvider, HttpEndpoint, Guard; `mcp:list`,
  `mcp:serve`), on top of rasuvaeff/mcp and rasuvaeff/mcp-openapi. Use when
  writing, reviewing or debugging MCP server code, its configuration or its
  route in a Laravel project that has this package installed.
---

# rasuvaeff/laravel-mcp

Laravel integration of `rasuvaeff/mcp`, namespace `Rasuvaeff\LaravelMcp`. The server itself
(interceptors, visibility, sessions, OAuth) is `rasuvaeff/mcp`: its own skill and
`vendor/rasuvaeff/mcp/llms.txt` hold the rules for tool code. Protocol attributes
come from `mcp/sdk` (`~0.10.0`): never invent them.

Do not confuse it with the official package (`laravel/mcp`, classes `Laravel\Mcp\*`): configuration and classes are different.

## Safety rules — verify these on every change

1. **The endpoint is trusted-only.** `guard: secret` needs `endpoint_secret` or
   `client_secrets` (503 without them), `guard: oauth` needs the `oauth` block. Never
   turn a missing secret into a pass-through; secrets come from the environment.
2. **Set `allowed_hosts`** to the public hostname: it is the DNS-rebinding protection.
3. **Several clients = `client_secrets`**, one id per client. A session belongs to the
   client that opened it; a shared secret makes them one client.
4. **No request state in services.** A worker serves many requests; read the client from
   `ToolCallContext::$clientId`, never from a property.
5. **Set `tool_call_budget.calls`** against agent loops; on the stateless era it counts in
   the framework's cache.
6. **Sessions need a shared store** when several hosts serve the endpoint: bind
   `SessionStoreInterface`; the default is an owner-only directory on one host.

## Canonical usage

```php
use Mcp\Server\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->status($orderId);
    }
}
```

```php
// config/rasuvaeff-mcp.php
'tools' => [App\Mcp\OrderTools::class],
'endpoint_secret' => env('MCP_SECRET', ''),
'allowed_hosts' => ['mcp.example.com'],
```

```bash
php artisan mcp:list    # what is served
php artisan mcp:serve   # stdio, for a local agent
```

## Laravel specifics

- The route is outside the `web` group: no cookie session and no CSRF token.
- The server is `scoped`: under Octane it is built per request and dropped with it. Sessions on disk and the notification bus stay.
- `config:cache` works: the config holds no closures, `apps.definitions[].html` is a string.

`route.path` is the endpoint (`mcp` by default, `''` registers none) and `route.middleware`
runs before the guard. `guard` is `secret` or `oauth`. Every other key is the one
`rasuvaeff/yii3-mcp` uses; the published file documents each.

Serve `ProtectedResourceMetadataHandler` from a route of your own at
`/.well-known/oauth-protected-resource/<path>`.

Test with `Rasuvaeff\Mcp\Testing\McpTester` over the container's `Mcp\Server\Server`,
on both protocol eras for anything session- or identity-related.

## Full API

Read `vendor/rasuvaeff/laravel-mcp/llms.txt` before guessing a class or option name.
