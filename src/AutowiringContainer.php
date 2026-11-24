<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp;

use Illuminate\Contracts\Container\Container;
use Psr\Container\ContainerInterface;

/**
 * Laravel's container answers `has()` for BOUND ids only, although it builds
 * any concrete class. The SDK asks `has()` before it resolves a tool class and
 * otherwise instantiates it without dependencies.
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
        return $this->container->get($id);
    }

    #[\Override]
    public function has(string $id): bool
    {
        return $this->container->has($id)
            || (class_exists($id) && (new \ReflectionClass($id))->isInstantiable());
    }
}
