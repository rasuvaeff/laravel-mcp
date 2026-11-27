# rasuvaeff/laravel-mcp

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/laravel-mcp?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/laravel-mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/laravel-mcp)](https://packagist.org/packages/rasuvaeff/laravel-mcp)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/laravel-mcp/build.yml?branch=master)](https://github.com/rasuvaeff/laravel-mcp/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/laravel-mcp/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/laravel-mcp/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/laravel-mcp/blob/master/psalm.xml)
[![License](https://img.shields.io/packagist/l/rasuvaeff/laravel-mcp)](LICENSE.md)

[Русская версия](README.ru.md)

Laravel integration of [`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp), a Model Context
Protocol server over the official [`mcp/sdk`](https://packagist.org/packages/mcp/sdk): tools from
the container, an HTTP endpoint, console commands, and the
[OpenAPI bridge](https://github.com/rasuvaeff/mcp-openapi).

[`laravel/mcp`](https://github.com/laravel/mcp) is the official package, with its own protocol implementation, generators and a client. Choose it unless you need what this one is for: the same server code in several frameworks, the OpenAPI bridge, or the `rasuvaeff/mcp` interceptors and visibility.

> **Using an AI coding assistant?** [llms.txt](llms.txt) contains a compact API
> reference you can share with the model. Contributors: see [AGENTS.md](AGENTS.md).
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.6 |
| `laravel/framework` | `^12.71 \|\| ^13.40` |
| `rasuvaeff/mcp` | `^0.1.4` |
| `rasuvaeff/mcp-openapi` | `^0.1.3` |
| `firebase/php-jwt` | optional, `^7.0`: JWT validation for OAuth |

## Installation

```bash
composer require rasuvaeff/laravel-mcp
php artisan vendor:publish --tag=rasuvaeff-mcp
```

## Usage

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

The endpoint is `/mcp`. Without a secret it answers 503 to everything.

```bash
php artisan mcp:list    # what is served
php artisan mcp:serve   # stdio, for a local agent
```

### Configuration

`route.path` is the endpoint (`mcp` by default, `''` registers none) and `route.middleware`
runs before the guard. `guard` is `secret` or `oauth`. Every other key is the one
`rasuvaeff/yii3-mcp` uses; the published file documents each.

Serve `ProtectedResourceMetadataHandler` from a route of your own at
`/.well-known/oauth-protected-resource/<path>`:

```php
// routes/web.php
Route::get(
    '/.well-known/oauth-protected-resource/mcp',
    fn(ServerRequestInterface $request, ProtectedResourceMetadataHandler $handler) => $handler->handle($request),
);
```

The OpenAPI bridge and OAuth call out through the PSR-18 `ClientInterface`: Guzzle by default,
a binding of yours wins. For a timeout bind `new Client(['timeout' => 5])`.

### Several nodes

Stateless calls need nothing. For sessions bind a shared store; notifications and the call budget
follow the cache of the application, so point it at Redis:

```php
$this->app->singleton(SessionStoreInterface::class, fn($app) => new Psr16SessionStore($app->make(CacheInterface::class)));
```

The call budget counts over PSR-16, which has no atomic increment: calls sent at the same moment
may all pass. It stops a loop, it is not a quota.

Keycloak puts your endpoint in `aud` only through an audience mapper on the client; without it
every token is refused with 401.

While the session store is unavailable, `initialize` still answers with a session id: the SDK logs the failed save, and the next request with that id gets 404, after which the client opens a new session.

### Long-running workers

- Checked on FrankenPHP (classic and worker), Swoole and RoadRunner. Under RoadRunner a `subscriptions/listen` stream arrives in one piece when it ends: the PSR-7 bridge captures the whole body. Keep `notifications.subscription_lifetime` short there, or serve listen streams from another runtime.
- `guard` other than `secret` or `oauth`, an empty one included, fails with `Unknown MCP guard`.
- The route is outside the `web` group: no cookie session and no CSRF token.
- The server is `scoped`: under Octane it is built per request and dropped with it. Sessions on disk and the notification bus stay.
- `config:cache` works: the config holds no closures, `apps.definitions[].html` is a string.

### Components

| Class | Role |
|---|---|
| `McpServiceProvider` | bindings, config, route and commands |
| `HttpEndpoint` | route action: guard, then the MCP endpoint |
| `Guard` | `secret` or `oauth` |

## Security

- The endpoint is trusted-only. Without `endpoint_secret`, `client_secrets` or `oauth` every request is refused.
- Set `allowed_hosts` to the public hostname: it is the DNS-rebinding protection, only localhost is allowed without it.
- Several clients need `client_secrets`: a session belongs to the client that opened it.
- A failure inside a tool is logged; the client never gets its message.

## Examples

See [examples/](examples/): the script runs offline.

## Development

No PHP/Composer on the host, run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
