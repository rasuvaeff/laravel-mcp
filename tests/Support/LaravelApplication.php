<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp\Tests\Support;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\LaravelMcp\McpServiceProvider;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * A real Laravel application with this package's provider, on a throwaway base path.
 */
final readonly class LaravelApplication
{
    private function __construct(public Application $app) {}

    /**
     * @param array<string, mixed> $config merged over the package's own
     */
    public static function boot(array $config = [], bool $configFile = true): self
    {
        $base = sys_get_temp_dir() . '/laravel-mcp-' . bin2hex(random_bytes(6));

        foreach (['bootstrap/cache', 'storage/framework/views', 'storage/logs', 'config'] as $directory) {
            mkdir($base . '/' . $directory, recursive: true);
        }

        $package = require dirname(__DIR__, levels: 2) . '/config/rasuvaeff-mcp.php';
        file_put_contents($base . '/config/app.php', '<?php return ' . var_export(['key' => str_repeat('k', 32), 'cipher' => 'AES-256-CBC', 'env' => 'testing', 'debug' => false], return: true) . ';');
        file_put_contents($base . '/config/cache.php', '<?php return ' . var_export(['default' => 'array', 'stores' => ['array' => ['driver' => 'array']], 'prefix' => ''], return: true) . ';');
        file_put_contents($base . '/config/logging.php', '<?php return ' . var_export(['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]], return: true) . ';');

        if ($configFile) {
            file_put_contents($base . '/config/' . McpServiceProvider::CONFIG . '.php', '<?php return ' . var_export(array_replace_recursive($package, ['session' => ['dir' => $base . '/sessions']], $config), return: true) . ';');
        }


        $app = Application::configure($base)
            ->withProviders([McpServiceProvider::class], withBootstrapProviders: false)
            ->withExceptions(static function (Exceptions $exceptions): void {})
            ->create();

        $app->make(Kernel::class)->bootstrap();
        // Laravel installs its handlers for the whole process; a test must not keep them
        restore_error_handler();
        restore_exception_handler();

        return new self($app);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $factory = new Psr17Factory();

        return (new PsrHttpFactory($factory, $factory, $factory, $factory))->createResponse($this->http($request));
    }

    public function http(ServerRequestInterface $request): Response
    {
        $kernel = $this->app->make(Kernel::class);
        $laravelRequest = Request::createFromBase((new HttpFoundationFactory())->createRequest($request));
        $response = $kernel->handle($laravelRequest);
        $kernel->terminate($laravelRequest, $response);
        // what Octane does between requests
        $this->app->forgetScopedInstances();

        return $response;
    }

    public function console(): ConsoleKernel
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->bootstrap();

        return $kernel;
    }
}
