<?php

namespace Plugin\EcAuthLogin40\Tests\Unit\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Plugin\EcAuthLogin40\Http\RequestFactoryInterface;
use Plugin\EcAuthLogin40\Http\StreamFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-17 実装 (nyholm/psr7) をプラグインの HTTP 抽象に合わせるテスト用アダプタ。
 *
 * 本番の実装は Http\GuzzleClient だが、ユニットテストのために Guzzle を持ち出す
 * 必要は無いので、テストでは依存の軽い nyholm/psr7 を使う。
 * プラグインが PSR-17 をそのまま使わない理由は Http\HttpClientInterface の
 * docblock を参照。
 */
class TestPsr17Factory implements RequestFactoryInterface, StreamFactoryInterface
{
    /**
     * @var Psr17Factory
     */
    private $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->factory->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->factory->createStream($content);
    }
}
