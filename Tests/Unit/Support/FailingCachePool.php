<?php

namespace Plugin\EcAuthLogin43\Tests\Unit\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * キャッシュバックエンドの障害を再現するテストダブル。
 *
 * PSR-6 は save() / getItem() が CacheException を投げることを許容しており、
 * Redis 等のアダプタは接続断で実際に投げる。JWKS 自体は取得できているのに
 * キャッシュ障害で認証が 500 になっては本末転倒なので、その挙動を検証する。
 */
class FailingCachePool implements CacheItemPoolInterface
{
    /**
     * @var ArrayAdapter
     */
    private $inner;

    /**
     * @var bool save() で例外を投げる
     */
    private $throwOnSave;

    /**
     * @var bool getItem() で例外を投げる
     */
    private $throwOnGetItem;

    public function __construct(bool $throwOnSave = false, bool $throwOnGetItem = false)
    {
        $this->inner = new ArrayAdapter();
        $this->throwOnSave = $throwOnSave;
        $this->throwOnGetItem = $throwOnGetItem;
    }

    public function getItem($key): CacheItemInterface
    {
        if ($this->throwOnGetItem) {
            throw new FakeCacheException('cache backend is unavailable');
        }

        return $this->inner->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems($keys);
    }

    public function hasItem($key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem($key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($this->throwOnSave) {
            throw new FakeCacheException('cache backend is unavailable');
        }

        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
