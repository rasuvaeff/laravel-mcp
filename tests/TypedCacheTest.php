<?php

declare(strict_types=1);

namespace Rasuvaeff\LaravelMcp\Tests;

use Illuminate\Cache\Repository;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\LaravelMcp\Tests\Support\NumbersAsStringsStore;
use Rasuvaeff\LaravelMcp\TypedCache;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TypedCache::class)]
final class TypedCacheTest
{
    #[DataProvider('values')]
    public function aValueKeepsItsType(mixed $value): void
    {
        $cache = new TypedCache($this->laravel());

        Assert::true($cache->set('key', $value, 60));
        Assert::same($cache->get('key', 'default'), $value);
        Assert::true($cache->setMultiple(['a' => $value, 'b' => 2], 60));
        Assert::same($cache->getMultiple(['a', 'b', 'c'], 'default'), ['a' => $value, 'b' => 2, 'c' => 'default']);
    }

    public static function values(): iterable
    {
        yield 'integer' => [3];
        yield 'zero' => [0];
        yield 'float' => [1.5];
        yield 'numeric string' => ['3'];
        yield 'array' => [['v' => 1]];
        yield 'false' => [false];
    }

    public function aValueWrittenBySomethingElseIsAMiss(): void
    {
        $inner = $this->laravel();
        $inner->set('plain', 3);
        $inner->set('keyed', ['v' => 1]);
        $cache = new TypedCache($inner);

        Assert::same($cache->get('plain', 'default'), 'default');
        Assert::same($cache->get('keyed', 'default'), 'default');
        Assert::null($cache->get('absent'));
    }

    public function theRestIsTheInnerCache(): void
    {
        $inner = $this->laravel();
        $cache = new TypedCache($inner);
        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);

        Assert::true($cache->has('a'));
        Assert::true($cache->delete('a'));
        Assert::false($inner->has('a'));
        Assert::false($cache->has('a'));
        Assert::true($cache->deleteMultiple(['b']));
        Assert::false($inner->has('b'));
        Assert::true($inner->has('c'));
        Assert::true($cache->clear());
        Assert::false($inner->has('c'));
    }

    public function aTtlExpires(): void
    {
        $inner = $this->laravel();
        $cache = new TypedCache($inner);
        $cache->set('a', 1, 0);
        $cache->setMultiple(['b' => 1], 0);

        Assert::false($inner->has('a'));
        Assert::false($inner->has('b'));
    }

    private function laravel(): CacheInterface
    {
        return new Repository(new NumbersAsStringsStore());
    }
}
