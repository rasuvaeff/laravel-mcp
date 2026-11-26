<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp;

use GuzzleHttp\Client;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mcp\Server\Fiber\FiberContextInterface;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\Mcp\Configuration\ServerComponentResolver;
use Rasuvaeff\Mcp\Configuration\ServerOptions;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\McpListCommand;
use Rasuvaeff\Mcp\McpServeCommand;
use Rasuvaeff\Mcp\McpServerAssembler;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\OAuth\BearerTokenMiddleware;
use Rasuvaeff\Mcp\OAuth\OAuthComponentFactory;
use Rasuvaeff\Mcp\OpenApi\OpenApiConfiguratorResolver;
use Rasuvaeff\Mcp\OpenApi\OpenApiServerConfigurator;
use Rasuvaeff\Mcp\SharedSecretMiddleware;

/**
 * Wires `rasuvaeff/mcp` into Laravel from the `rasuvaeff-mcp` config file.
 *
 * What serves one request is `scoped`: under Octane the server is built from
 * the sandbox application of that request and dropped with it. What outlives a
 * request by design (sessions on disk, the notification bus) is a singleton.
 *
 * @api
 */
final class McpServiceProvider extends ServiceProvider
{
    public const string CONFIG = 'rasuvaeff-mcp';

    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rasuvaeff-mcp.php', self::CONFIG);

        foreach ([ResponseFactoryInterface::class, StreamFactoryInterface::class, ServerRequestFactoryInterface::class, RequestFactoryInterface::class] as $factory) {
            $this->app->bindIf($factory, Psr17Factory::class);
        }

        // the OpenAPI bridge and OAuth discovery call out; Laravel ships Guzzle but binds no PSR-18 client
        $this->app->bindIf(ClientInterface::class, static fn(): ClientInterface => new Client());

        $this->app->singleton(ServerOptions::class, static fn(Application $app): ServerOptions => ServerOptions::fromParams(self::params($app)));
        $this->app->singletonIf(SessionStoreInterface::class, static fn(Application $app): SessionStoreInterface => $app->make(ServerOptions::class)->sessionStore());
        $this->app->singletonIf(SubscriptionManagerInterface::class, SessionSubscriptionManager::class);

        // bound only when configured: ResourceUpdateNotifier takes it as an
        // optional dependency and must share the server's instance
        if (($this->config()['notifications']['bus'] ?? '') !== '') {
            $this->app->singletonIf(NotificationBusInterface::class, static fn(Application $app): ?NotificationBusInterface => $app->make(ServerOptions::class)->notificationBus(new AutowiringContainer($app)));
        }

        $this->app->scoped(McpServerFactory::class, static fn(Application $app): McpServerFactory => $app->make(ServerOptions::class)->serverFactory(
            container: new AutowiringContainer($app),
            sessionStore: $app->make(SessionStoreInterface::class),
            logger: $app->make(LoggerInterface::class),
            subscriptionManager: $app->make(SubscriptionManagerInterface::class),
            notificationBus: $app->bound(NotificationBusInterface::class) ? $app->make(NotificationBusInterface::class) : null,
            fiberContext: $app->bound(FiberContextInterface::class) ? $app->make(FiberContextInterface::class) : null,
        ));
        $this->app->scoped(Server::class, static function (Application $app): Server {
            $params = self::params($app);
            /** @var array<string, mixed> $openapi */
            $openapi = $params['openapi'] ?? [];
            $bridge = (new OpenApiConfiguratorResolver(new AutowiringContainer($app), $openapi))->resolve();

            return (new McpServerAssembler(
                factory: $app->make(McpServerFactory::class),
                components: (new ServerComponentResolver(new AutowiringContainer($app), $params))->resolve($bridge instanceof OpenApiServerConfigurator ? [$bridge] : []),
            ))->create();
        });
        $this->app->scoped(McpAction::class, static fn(Application $app): McpAction => new McpAction(
            server: $app->make(Server::class),
            responseFactory: $app->make(ResponseFactoryInterface::class),
            streamFactory: $app->make(StreamFactoryInterface::class),
            allowedHosts: $app->make(ServerOptions::class)->allowedHosts(),
            sessionStore: $app->make(SessionStoreInterface::class),
        ));
        $this->app->bind(SharedSecretMiddleware::class, static fn(Application $app): SharedSecretMiddleware => $app->make(ServerOptions::class)->sharedSecretMiddleware($app->make(ResponseFactoryInterface::class)));
        $this->app->bind(BearerTokenMiddleware::class, static fn(Application $app): BearerTokenMiddleware => self::oauth($app)->bearerTokenMiddleware());
        $this->app->bind(ProtectedResourceMetadataHandler::class, static fn(Application $app): ProtectedResourceMetadataHandler => self::oauth($app)->metadataHandler());
        $this->app->bind(HttpEndpoint::class, static fn(Application $app): HttpEndpoint => new HttpEndpoint(
            action: $app->make(McpAction::class),
            guard: match (Guard::fromConfig(self::params($app)['guard'] ?? Guard::Secret->value)) {
                Guard::Secret => $app->make(SharedSecretMiddleware::class),
                Guard::OAuth => $app->make(BearerTokenMiddleware::class),
            },
        ));
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__ . '/../config/rasuvaeff-mcp.php' => $this->app->configPath(self::CONFIG . '.php')], self::CONFIG);

        if ($this->app->runningInConsole()) {
            $this->commands([McpServeCommand::class, McpListCommand::class]);
        }

        /** @var array{path?: string, middleware?: list<string>} $route */
        $route = $this->config()['route'] ?? [];
        $path = $route['path'] ?? '';

        // outside the "web" group: no cookie session and no CSRF token here
        if ($path !== '') {
            $router->match(['GET', 'POST', 'DELETE', 'OPTIONS'], $path, HttpEndpoint::class)
                ->middleware($route['middleware'] ?? [])
                ->name('rasuvaeff-mcp');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return self::params($this->app);
    }

    /**
     * @return array<string, mixed>
     */
    private static function params(Application $app): array
    {
        /** @var array<string, mixed> */
        return $app->make(Repository::class)->get(self::CONFIG, []);
    }

    private static function oauth(Application $app): OAuthComponentFactory
    {
        /** @var array<string, mixed> $oauth */
        $oauth = self::params($app)['oauth'] ?? [];

        return new OAuthComponentFactory(params: $oauth, container: $app);
    }
}
