<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp;

use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\MiddlewareInterface;
use Rasuvaeff\Mcp\McpAction;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route action: the guard, then {@see McpAction}, over PSR-7.
 *
 * @api
 */
final readonly class HttpEndpoint
{
    public function __construct(
        private McpAction $action,
        private MiddlewareInterface $guard,
    ) {}

    public function __invoke(Request $request): Response
    {
        $factory = new Psr17Factory();
        $response = $this->guard->process(
            (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request),
            $this->action,
        );

        // a listen stream must leave frame by frame: Laravel's own PSR-7
        // conversion reads the whole body first
        return (new HttpFoundationFactory())->createResponse(
            $response,
            streamed: str_starts_with($response->getHeaderLine('Content-Type'), 'text/event-stream'),
        );
    }
}
