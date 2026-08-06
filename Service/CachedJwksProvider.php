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
     * 強制再取得（kid 不一致時）を許可する最短間隔（秒）。
     *
     * kid は id_token のヘッダから来るため、kid を変え続けるトークンを渡されると
     * キャッシュを迂回して JWKS エンドポイントへのリクエストを増幅できてしまう。
     * EcAuth 側の設定ミスで「JWKS に無い kid」が発行され続ける状況でも、
     * ログインのたびに 2 回 HTTP を叩くことになる。クールダウンで上限を設ける。
     */
    private const FORCED_REFRESH_COOLDOWN = 60;

    /**
     * クールダウン中であることを示すマーカーのキー接頭辞。
     */
    private const COOLDOWN_KEY_PREFIX = 'ecauth_jwks_forced_';

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

        $cached = null;
        if ($item !== null && $item->isHit()) {
            $hit = $item->get();
            if (is_array($hit) && $hit !== []) {
                $cached = $hit;
            }
        }

        if (!$forceRefresh && $cached !== null) {
            return $cached;
        }

        // 直近に強制再取得したばかりなら、キャッシュを返して外部リクエストを抑える。
        // キャッシュが空のときは返せるものが無いので取得を許可する。
        if ($forceRefresh && $cached !== null && !$this->tryConsumeForcedRefresh($baseUrl)) {
            return $cached;
        }

        $keys = $this->fetch($baseUrl);
        if ($keys === null) {
            return null;
        }

        if ($item !== null) {
            $item->set($keys);
            $item->expiresAfter(self::CACHE_TTL);
            // 保存に失敗しても取得自体は成立しているので続行する。ただし黙って
            // 落とすとログインのたびに JWKS を取りに行く状態に気付けないため記録する。
            if (!$this->cache->save($item)) {
                $this->logger->warning('Failed to cache the EcAuth JWKS');
            }
        }

        return $keys;
    }

    /**
     * 強制再取得のクールダウンを消費する。
     *
     * まだクールダウン中なら false を返し、呼び出し側は再取得を見送る。
     * 許可した場合はマーカーを立てて、次の呼び出しから一定時間ブロックする。
     */
    private function tryConsumeForcedRefresh(string $baseUrl): bool
    {
        $cooldownKey = self::COOLDOWN_KEY_PREFIX.hash('sha256', $baseUrl);

        try {
            $marker = $this->cache->getItem($cooldownKey);
        } catch (CacheInvalidArgumentException $e) {
            // クールダウンを管理できない場合は従来どおり再取得を許可する
            return true;
        }

        if ($marker->isHit()) {
            $this->logger->warning('Skipping the forced EcAuth JWKS refresh; still in cooldown');

            return false;
        }

        $marker->set(true);
        $marker->expiresAfter(self::FORCED_REFRESH_COOLDOWN);
        $this->cache->save($marker);

        return true;
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
