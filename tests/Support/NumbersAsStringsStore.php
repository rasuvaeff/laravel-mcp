<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Store;

/** What Laravel's Redis store does to a number. */
final readonly class NumbersAsStringsStore implements Store
{
    public function __construct(private ArrayStore $store) {}

    #[\Override]
    public function get($key): mixed
    {
        $value = $this->store->get($key);

        return \is_int($value) || \is_float($value) ? (string) $value : $value;
    }

    #[\Override]
    public function many(array $keys): array
    {
        return array_combine($keys, array_map($this->get(...), $keys));
    }

    #[\Override]
    public function put($key, $value, $seconds): bool
    {
        return $this->store->put($key, $value, $seconds);
    }

    #[\Override]
    public function putMany(array $values, $seconds): bool
    {
        return $this->store->putMany($values, $seconds);
    }

    #[\Override]
    public function increment($key, $value = 1): int|bool
    {
        return $this->store->increment($key, $value);
    }

    #[\Override]
    public function decrement($key, $value = 1): int|bool
    {
        return $this->store->decrement($key, $value);
    }

    #[\Override]
    public function forever($key, $value): bool
    {
        return $this->store->forever($key, $value);
    }

    #[\Override]
    public function touch($key, $seconds): bool
    {
        return $this->store->touch($key, $seconds);
    }

    #[\Override]
    public function forget($key): bool
    {
        return $this->store->forget($key);
    }

    #[\Override]
    public function flush(): bool
    {
        return $this->store->flush();
    }

    #[\Override]
    public function getPrefix(): string
    {
        return $this->store->getPrefix();
    }
}
