<?php

namespace Plugin\EcAuthLogin40\Http;

use Psr\Http\Message\RequestInterface;

/**
 * PSR-7 リクエストのファクトリ。PSR-17 の
 * Psr\Http\Message\RequestFactoryInterface と同じ形にしてある。
 *
 * 自前で持つ理由は HttpClientInterface の docblock を参照。
 */
interface RequestFactoryInterface
{
    /**
     * @param string $method HTTP メソッド
     * @param string|\Psr\Http\Message\UriInterface $uri
     */
    public function createRequest(string $method, $uri): RequestInterface;
}
