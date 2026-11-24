<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp\Benchmarks;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\LaravelMcp\Tests\Support\ClockTool;
use Rasuvaeff\LaravelMcp\Tests\Support\LaravelApplication;
use Testo\Bench;

final class HttpEndpointBench
{
    private static ?LaravelApplication $application = null;

    public static function initialize(): int
    {
        return self::application()->handle(new ServerRequest('POST', 'http://localhost/mcp', [
            'X-Mcp-Secret' => 'secret',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ], '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"bench","version":"1"}}}'))->getStatusCode();
    }

    #[Bench(
        callables: [
            'initialize' => [self::class, 'initialize'],
        ],
        calls: 50,
        iterations: 5,
    )]
    public static function refusedWithoutASecret(): int
    {
        return self::application()->handle(new ServerRequest('POST', 'http://localhost/mcp'))->getStatusCode();
    }

    private static function application(): LaravelApplication
    {
        return self::$application ??= LaravelApplication::boot(['tools' => [ClockTool::class], 'endpoint_secret' => 'secret']);
    }
}
