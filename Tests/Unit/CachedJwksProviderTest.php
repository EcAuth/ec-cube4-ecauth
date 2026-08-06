<?php

namespace Plugin\EcAuthLogin43\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Plugin\EcAuthLogin43\Service\CachedJwksProvider;
use Plugin\EcAuthLogin43\Tests\Unit\Support\FakeClientException;
use Plugin\EcAuthLogin43\Tests\Unit\Support\FakeHttpClient;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CachedJwksProviderTest extends TestCase
{
    private const BASE_URL = 'https://tenant.ec-auth.io';

    public function testFetchesAndCachesKeys(): void
    {
        $client = new FakeHttpClient([
            ['status' => 200, 'body' => $this->jwksBody('kid-1')],
        ]);
        $cache = new ArrayAdapter();
        $provider = $this->createProvider($client, $cache);

        $first = $provider->getJwks(self::BASE_URL);
        $second = $provider->getJwks(self::BASE_URL);

        self::assertIsArray($first);
        self::assertSame('kid-1', $first[0]['kid']);
        // 2 回目はキャッシュから返るため HTTP リクエストは 1 回だけ
        self::assertCount(1, $client->requests);
        self::assertSame($first, $second);
        self::assertCount(1, $cache->getValues());
    }

    public function testRequestsTheJwksEndpointOfTheGivenBaseUrl(): void
    {
        $client = new FakeHttpClient([
            ['status' => 200, 'body' => $this->jwksBody('kid-1')],
        ]);
        $this->createProvider($client, new ArrayAdapter())->getJwks(self::BASE_URL.'/');

        self::assertSame(
            self::BASE_URL.'/.well-known/jwks.json',
            (string) $client->requests[0]->getUri(),
        );
    }

    public function testForceRefreshBypassesCache(): void
    {
        $client = new FakeHttpClient([
            ['status' => 200, 'body' => $this->jwksBody('kid-1')],
            ['status' => 200, 'body' => $this->jwksBody('kid-2')],
        ]);
        $provider = $this->createProvider($client, new ArrayAdapter());

        $provider->getJwks(self::BASE_URL);
        $refreshed = $provider->getJwks(self::BASE_URL, true);

        self::assertIsArray($refreshed);
        self::assertSame('kid-2', $refreshed[0]['kid']);
        self::assertCount(2, $client->requests);
    }

    public function testRepeatedForceRefreshIsRateLimited(): void
    {
        // kid を変え続けるトークンで JWKS エンドポイントへのリクエストを
        // 増幅できないこと（キャッシュがある限りクールダウン中は取りに行かない）
        $client = new FakeHttpClient([
            ['status' => 200, 'body' => $this->jwksBody('kid-1')],
            ['status' => 200, 'body' => $this->jwksBody('kid-2')],
        ]);
        $provider = $this->createProvider($client, new ArrayAdapter());

        $provider->getJwks(self::BASE_URL);
        $provider->getJwks(self::BASE_URL, true);
        for ($i = 0; $i < 10; ++$i) {
            $throttled = $provider->getJwks(self::BASE_URL, true);
            self::assertIsArray($throttled);
        }

        // 初回取得 + 1 回目の強制再取得のみ。以降はクールダウンで抑制される
        self::assertCount(2, $client->requests);
    }

    public function testForceRefreshIsAllowedWhenNothingIsCached(): void
    {
        // キャッシュが無ければ返せるものが無いので、クールダウンより取得を優先する
        $client = new FakeHttpClient([
            ['status' => 200, 'body' => $this->jwksBody('kid-1')],
        ]);
        $provider = $this->createProvider($client, new ArrayAdapter());

        self::assertIsArray($provider->getJwks(self::BASE_URL, true));
        self::assertCount(1, $client->requests);
    }

    public function testCacheKeyIsDerivedFromBaseUrl(): void
    {
        $client = new FakeHttpClient([
            ['status' => 200, 'body' => $this->jwksBody('kid-a')],
            ['status' => 200, 'body' => $this->jwksBody('kid-b')],
        ]);
        $provider = $this->createProvider($client, new ArrayAdapter());

        $a = $provider->getJwks('https://tenant-a.ec-auth.io');
        $b = $provider->getJwks('https://tenant-b.ec-auth.io');

        // テナントごとに鍵が異なるため、キャッシュが混ざってはいけない
        self::assertSame('kid-a', $a[0]['kid']);
        self::assertSame('kid-b', $b[0]['kid']);
    }

    public function testHttpErrorReturnsNull(): void
    {
        $client = new FakeHttpClient([['status' => 500, 'body' => '']]);

        self::assertNull($this->createProvider($client, new ArrayAdapter())->getJwks(self::BASE_URL));
    }

    public function testTransportErrorReturnsNull(): void
    {
        $client = new FakeHttpClient([new FakeClientException('connection refused')]);

        self::assertNull($this->createProvider($client, new ArrayAdapter())->getJwks(self::BASE_URL));
    }

    public function testMalformedResponseReturnsNull(): void
    {
        $cache = new ArrayAdapter();

        self::assertNull(
            $this->createProvider(new FakeHttpClient([['status' => 200, 'body' => 'not json']]), $cache)
                ->getJwks(self::BASE_URL),
        );
        self::assertNull(
            $this->createProvider(new FakeHttpClient([['status' => 200, 'body' => '{"keys":[]}']]), $cache)
                ->getJwks(self::BASE_URL),
        );
        self::assertNull(
            $this->createProvider(new FakeHttpClient([['status' => 200, 'body' => '{"foo":1}']]), $cache)
                ->getJwks(self::BASE_URL),
        );
        // 取得失敗をキャッシュしてはいけない。
        // ArrayAdapter はミス時にもキーを null で保持するため、実値だけを見る。
        self::assertSame([], array_filter($cache->getValues(), static function ($value) {
            return $value !== null;
        }));
    }

    public function testEmptyBaseUrlReturnsNullWithoutRequest(): void
    {
        $client = new FakeHttpClient([]);

        self::assertNull($this->createProvider($client, new ArrayAdapter())->getJwks(''));
        self::assertCount(0, $client->requests);
    }

    private function createProvider(FakeHttpClient $client, ArrayAdapter $cache): CachedJwksProvider
    {
        return new CachedJwksProvider($client, new Psr17Factory(), $cache, new NullLogger());
    }

    private function jwksBody(string $kid): string
    {
        return (string) json_encode([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $kid,
                    'n' => 'dGVzdC1tb2R1bHVz',
                    'e' => 'AQAB',
                ],
            ],
        ]);
    }
}
