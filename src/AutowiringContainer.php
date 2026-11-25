<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp;

use Illuminate\Contracts\Container\Container;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

/**
 * Laravel's container answers `has()` for BOUND ids only, although it builds
 * any concrete class. The SDK asks `has()` before it resolves a tool class and
 * otherwise instantiates it without dependencies.
 *
 * The cache it answers with keeps the types of numbers, see {@see TypedCache}.
 *
 * @internal
 */
final readonly class AutowiringContainer implements ContainerInterface
{
    public function __construct(
        private Container $container,
    ) {}

    #[\Override]
    public function get(string $id): mixed
    {
        return match ($id) {
            CacheInterface::class => new TypedCache($this->container->make(CacheInterface::class)),
            CacheItemPoolInterface::class => $this->pool(),
            default => $this->container->get($id),
        };
    }

    #[\Override]
    public function has(string $id): bool
    {
        return $this->container->has($id)
            || (class_exists($id) && (new \ReflectionClass($id))->isInstantiable());
    }

    private function pool(): CacheItemPoolInterface
    {
        $pool = $this->container->make(CacheItemPoolInterface::class);

        // Laravel's own pool wraps the same store; one the application bound is left alone
        return $pool instanceof Psr16Adapter
            ? new Psr16Adapter(new TypedCache($this->container->make(CacheInterface::class)))
            : $pool;
    }
}
