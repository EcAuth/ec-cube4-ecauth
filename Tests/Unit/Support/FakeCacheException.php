<?php

namespace Plugin\EcAuthLogin40\Tests\Unit\Support;

use Psr\Cache\CacheException;

/**
 * キャッシュバックエンド障害を再現するための PSR-6 例外。
 */
class FakeCacheException extends \RuntimeException implements CacheException
{
}
