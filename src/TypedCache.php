<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp;

use Psr\SimpleCache\CacheInterface;

/**
 * Laravel's Redis store writes a number as is and returns it as a string. The
 * counters of the call budget and of the notification bus are compared as
 * integers, so a value travels inside an array, which is always serialized.
 *
 * @internal
 */
final readonly class TypedCache implements CacheInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->unwrap($this->cache->get($key), $default);
    }

    #[\Override]
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        return $this->cache->set($key, [$value], $ttl);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    #[\Override]
    public function clear(): bool
    {
        return $this->cache->clear();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        /** @var mixed $value */
        foreach ($this->cache->getMultiple($keys) as $key => $value) {
            /** @psalm-var mixed */
            $values[$key] = $this->unwrap($value, $default);
        }

        return $values;
    }

    #[\Override]
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $wrapped = [];

        /**
         * @var array-key $key
         * @var mixed $value
         */
        foreach ($values as $key => $value) {
            $wrapped[(string) $key] = [$value];
        }

        return $this->cache->setMultiple($wrapped, $ttl);
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->cache->deleteMultiple($keys);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    private function unwrap(mixed $value, mixed $default): mixed
    {
        return \is_array($value) && \array_key_exists(0, $value) ? $value[0] : $default;
    }
}
