<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Mcp\Server\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\LaravelMcp\AutowiringContainer;
use Rasuvaeff\LaravelMcp\Guard;
use Rasuvaeff\LaravelMcp\HttpEndpoint;
use Rasuvaeff\LaravelMcp\McpServiceProvider;
use Rasuvaeff\LaravelMcp\Tests\Support\ClockTool;
use Rasuvaeff\LaravelMcp\Tests\Support\LaravelApplication;
use Rasuvaeff\LaravelMcp\Tests\Support\NotFoundHandler;
use Rasuvaeff\Mcp\Configuration\ServerOptions;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Mcp\Testing\AdapterContractTool;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(McpServiceProvider::class)]
#[Covers(HttpEndpoint::class)]
#[Covers(AutowiringContainer::class)]
#[Covers(Guard::class)]
final class McpServiceProviderTest
{
    public function theRouteIsRegisteredOutsideTheWebGroup(): void
    {
        $route = $this->route($this->boot());

        Assert::same($route->uri(), 'mcp');
        Assert::same($route->methods(), ['GET', 'POST', 'DELETE', 'OPTIONS', 'HEAD']);
        Assert::same($route->gatherMiddleware(), []);
    }

    public function pathAndMiddlewareAreConfigurable(): void
    {
        $route = $this->route($this->boot(['route' => ['path' => 'agents/mcp', 'middleware' => ['throttle:5']]]));

        Assert::same($route->uri(), 'agents/mcp');
        Assert::same($route->gatherMiddleware(), ['throttle:5']);
    }

    public function anEmptyPathRegistersNoRoute(): void
    {
        Assert::null($this->route($this->boot(['route' => ['path' => '']])));
    }

    public function theDefaultConfigurationRefusesEveryRequest(): void
    {
        Assert::same(LaravelApplication::boot()->handle(new ServerRequest('POST', 'http://localhost/mcp'))->getStatusCode(), 503);
    }

    public function whatServesARequestIsDroppedWithItAndTheRestStays(): void
    {
        $app = $this->boot()->app;
        $server = $app->make(Server::class);
        $action = $app->make(McpAction::class);
        $store = $app->make(SessionStoreInterface::class);

        Assert::same($app->make(Server::class), $server);
        $app->forgetScopedInstances();

        Assert::notSame($app->make(Server::class), $server);
        Assert::notSame($app->make(McpAction::class), $action);
        Assert::same($app->make(SessionStoreInterface::class), $store);
    }

    /**
     * @param class-string|null $expected
     */
    #[DataProvider('busProvider')]
    public function theNotificationBusIsOneSharedInstance(string $kind, ?string $expected): void
    {
        $app = $this->boot(['notifications' => ['bus' => $kind]])->app;

        Assert::same($app->bound(NotificationBusInterface::class), $expected !== null);

        if ($expected !== null) {
            $bus = $app->make(NotificationBusInterface::class);
            Assert::instanceOf($bus, $expected);
            $app->forgetScopedInstances();
            Assert::same($app->make(NotificationBusInterface::class), $bus);
        }
    }

    public static function busProvider(): iterable
    {
        yield 'none' => ['', null];
        yield 'memory' => ['memory', InMemoryNotificationBus::class];
        yield 'psr16' => ['psr16', Psr16NotificationBus::class];
    }

    public function applicationBindingsWin(): void
    {
        $app = $this->boot()->app;
        $factory = new Psr17Factory();
        $app->instance(ResponseFactoryInterface::class, $factory);

        Assert::same($app->make(ResponseFactoryInterface::class), $factory);
    }

    public function onlyAllowedHostsAreServed(): void
    {
        $application = $this->boot(['allowed_hosts' => ['mcp.test']]);
        $status = static fn(string $host): int => $application->handle(new ServerRequest('POST', 'http://' . $host . '/mcp', [
            'Host' => $host,
            'X-Mcp-Secret' => 'secret',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ], '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}'))->getStatusCode();

        Assert::same($status('mcp.test'), 200);
        Assert::same($status('evil.test'), 403);
    }

    public function theOAuthGuardNeedsItsParams(): void
    {
        $app = $this->boot(['guard' => 'oauth'])->app;
        $caught = null;

        try {
            $app->make(HttpEndpoint::class);
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('OAuth is not configured');
    }

    public function oauthMetadataIsBuiltFromTheConfig(): void
    {
        $app = $this->boot(['oauth' => ['resource' => 'https://mcp.test/mcp', 'authorization_servers' => ['https://id.test']]])->app;

        Assert::instanceOf($app->make(ProtectedResourceMetadataHandler::class), ProtectedResourceMetadataHandler::class);
    }

    public function anUnknownGuardFails(): void
    {
        $caught = null;

        try {
            $this->boot(['guard' => 'none'])->app->make(HttpEndpoint::class);
        } catch (\ValueError $caught) {
        }

        Assert::notNull($caught);
    }

    public function anUnsupportedParamFailsNamingIt(): void
    {
        $caught = null;

        try {
            $this->boot(['result_json' => 'compct'])->app->make(Server::class);
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains('result_json');
    }

    public function theConsoleCommandListsTheTools(): void
    {
        $application = $this->boot();
        $output = new BufferedOutput();
        $status = $application->console()->call('mcp:list', [], $output);

        Assert::same($status, 0);
        Assert::string($output->fetch())->contains('clock');
        Assert::true(array_key_exists('mcp:serve', $application->console()->all()));
        Assert::true(array_key_exists('mcp:list', $application->console()->all()));
    }

    public function theConfigFileIsPublished(): void
    {
        $app = $this->boot()->app;
        $paths = McpServiceProvider::pathsToPublish(McpServiceProvider::class, 'rasuvaeff-mcp');

        Assert::same(array_map(realpath(...), array_keys($paths))[0], realpath(__DIR__ . '/../config/rasuvaeff-mcp.php'));
        Assert::true(in_array($app->configPath('rasuvaeff-mcp.php'), $paths, strict: true));
    }

    public function theConfigFileCanBeCached(): void
    {
        $config = require __DIR__ . '/../config/rasuvaeff-mcp.php';

        Assert::equals(eval('return ' . var_export($config, return: true) . ';'), $config);
    }

    public function anUnpublishedConfigUsesThePackageDefaults(): void
    {
        $application = LaravelApplication::boot(configFile: false);

        Assert::same($application->app->make('config')->get('rasuvaeff-mcp.route.path'), 'mcp');
        Assert::same($application->handle(new ServerRequest('POST', 'http://localhost/mcp'))->getStatusCode(), 503);
    }

    public function theFactoriesAreBoundOnlyWhenTheApplicationHasNone(): void
    {
        $app = $this->boot()->app;

        foreach ([ResponseFactoryInterface::class, StreamFactoryInterface::class, ServerRequestFactoryInterface::class, RequestFactoryInterface::class] as $factory) {
            Assert::instanceOf($app->make($factory), Psr17Factory::class);
        }
    }

    public function openApiOperationsBecomeTools(): void
    {
        $spec = tempnam(sys_get_temp_dir(), prefix: 'spec');
        file_put_contents($spec, json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => ['/items' => ['get' => ['operationId' => 'listItems', 'responses' => ['200' => ['description' => 'ok']]]]],
        ], JSON_THROW_ON_ERROR));
        $output = new BufferedOutput();
        $this->boot(['openapi' => ['spec_path' => $spec, 'base_url' => 'https://api.test', 'operations' => ['listItems'], 'executor' => 'psr15', 'handler' => NotFoundHandler::class]])
            ->console()->call('mcp:list', [], $output);
        unlink($spec);

        Assert::string($output->fetch())->contains('listItems');
    }

    public function aListenStreamIsNotBuffered(): void
    {
        Assert::instanceOf($this->endpoint('text/event-stream; charset=utf-8')(Request::create('/mcp', 'POST')), StreamedResponse::class);
        Assert::false($this->endpoint('application/json')(Request::create('/mcp', 'POST')) instanceof StreamedResponse);
    }

    public function theContainerKnowsWhatLaravelCanBuild(): void
    {
        $app = $this->boot()->app;
        $container = new AutowiringContainer($app);

        Assert::true($container->has(AdapterContractTool::class));
        Assert::instanceOf($container->get(AdapterContractTool::class), AdapterContractTool::class);
        Assert::true($container->has(Kernel::class));
        Assert::false($container->has(MiddlewareInterface::class));
        Assert::false($container->has(\FilterIterator::class));
        Assert::false($container->has('nothing'));
    }

    public function theSecretMiddlewareComesFromTheConfig(): void
    {
        $app = $this->boot()->app;

        Assert::equals($app->make(SharedSecretMiddleware::class), $app->make(ServerOptions::class)->sharedSecretMiddleware($app->make(ResponseFactoryInterface::class)));
    }

    private function route(LaravelApplication $application): ?\Illuminate\Routing\Route
    {
        $routes = $application->app->make('router')->getRoutes();
        $routes->refreshNameLookups();

        return $routes->getByName('rasuvaeff-mcp');
    }

    private function endpoint(string $contentType): HttpEndpoint
    {
        return new HttpEndpoint(
            $this->boot()->app->make(McpAction::class),
            new readonly class ($contentType) implements MiddlewareInterface {
                public function __construct(private string $contentType) {}

                #[\Override]
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return new Response(200, ['Content-Type' => $this->contentType], 'data: {}');
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): LaravelApplication
    {
        return LaravelApplication::boot($config + ['tools' => [ClockTool::class], 'endpoint_secret' => 'secret']);
    }
}
