<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\LaravelMcp\HttpEndpoint;
use Rasuvaeff\LaravelMcp\McpServiceProvider;
use Rasuvaeff\LaravelMcp\Tests\Support\LaravelApplication;
use Rasuvaeff\Mcp\Testing\AdapterContract;
use Rasuvaeff\Mcp\Testing\AdapterContractTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The shared adapter contract through Laravel's own HTTP kernel.
 */
#[Test]
#[Covers(McpServiceProvider::class)]
#[Covers(HttpEndpoint::class)]
final class AdapterContractTest
{
    private const array SECRETS = ['alpha' => 'secret-a', 'beta' => 'secret-b'];

    private static ?AdapterContract $contract = null;

    #[DataProvider('checkProvider')]
    public function keepsTheAdapterContract(string $check): void
    {
        self::contract()->checks()[$check]();
        Assert::true(actual: true);
    }

    public static function checkProvider(): iterable
    {
        foreach (array_keys(self::contract()->checks()) as $check) {
            yield $check => [$check];
        }
    }

    private static function contract(): AdapterContract
    {
        $factory = new Psr17Factory();

        return self::$contract ??= new AdapterContract(
            handle: LaravelApplication::boot([
                'tools' => [AdapterContractTool::class],
                'client_secrets' => self::SECRETS,
                'endpoint_secret' => '',
            ])->handle(...),
            requestFactory: $factory,
            streamFactory: $factory,
            uri: 'http://localhost/mcp',
            secrets: self::SECRETS,
        );
    }
}
