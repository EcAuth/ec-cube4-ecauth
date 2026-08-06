<?php

namespace Plugin\EcAuthLogin43\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as CacheInvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * EcAuth の JWKS エンドポイント（{base_url}/.well-known/jwks.json）から
 * 公開鍵を取得し、PSR-6 キャッシュに一定時間保持する。
 *
 * JWKS は Organization（テナント）ごとに異なるため、キャッシュキーは Base URL から導出する。
 */
class CachedJwksProvider implements JwksProviderInterface
{
    private const JWKS_PATH = '/.well-known/jwks.json';

    /**
     * キャッシュキーの接頭辞。PSR-6 の予約文字 {}()/\@: を含めないこと。
     */
    private const CACHE_KEY_PREFIX = 'ecauth_jwks_';

    /**
     * JWKS のキャッシュ保持秒数。
     *
     * 短すぎるとログインのたびに EcAuth へ HTTP リクエストが飛び、長すぎると
     * 鍵ローテーション直後の追従が遅れる。kid 不一致時は forceRefresh で
     * 即時再取得されるため、通常運用ではこの TTL が追従性の上限にはならない。
     */
    private const CACHE_TTL = 300;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        CacheItemPoolInterface $cache,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function getJwks(string $baseUrl, bool $forceRefresh = false): ?array
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return null;
        }

        $cacheKey = self::CACHE_KEY_PREFIX.hash('sha256', $baseUrl);

        try {
            $item = $this->cache->getItem($cacheKey);
        } catch (CacheInvalidArgumentException $e) {
            // キャッシュが使えなくても取得自体は継続する
            $this->logger->warning('EcAuth JWKS cache unavailable', [
                'error' => $e->getMessage(),
            ]);
            $item = null;
        }

        if (!$forceRefresh && $item !== null && $item->isHit()) {
            $cached = $item->get();
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $keys = $this->fetch($baseUrl);
        if ($keys === null) {
            return null;
        }

        if ($item !== null) {
            $item->set($keys);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        return $keys;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetch(string $baseUrl): ?array
    {
        $request = $this->requestFactory
            ->createRequest('GET', $baseUrl.self::JWKS_PATH)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('EcAuth JWKS request failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            $this->logger->error('EcAuth JWKS endpoint returned an error', [
                'status' => $statusCode,
            ]);

            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            $this->logger->error('EcAuth JWKS response is malformed');

            return null;
        }

        $keys = [];
        foreach ($decoded['keys'] as $key) {
            if (is_array($key)) {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            $this->logger->error('EcAuth JWKS response contains no usable key');

            return null;
        }

        return $keys;
    }
}
