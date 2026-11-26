<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp\Tests\Support;

use Illuminate\Cache\ArrayStore;

/** What Laravel's Redis store does to a number: it comes back as a string. */
final class NumbersAsStringsStore extends ArrayStore
{
    public function __construct()
    {
        parent::__construct(serializesValues: true);
    }

    #[\Override]
    public function get($key): mixed
    {
        $value = parent::get($key);

        return \is_int($value) || \is_float($value) ? (string) $value : $value;
    }

    #[\Override]
    public function many(array $keys): array
    {
        return array_combine($keys, array_map($this->get(...), $keys));
    }
}
