<?php

namespace Plugin\EcAuthLogin43\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\EcAuthLogin43\Service\IdTokenVerifier;
use Plugin\EcAuthLogin43\Tests\Unit\Support\FakeJwksProvider;
use Plugin\EcAuthLogin43\Tests\Unit\Support\RsaKeyFixture;
use Psr\Log\NullLogger;

/**
 * EcAuthDocs #101 の回帰テスト。
 *
 * id_token は管理者セッション確立の起点になるため、署名・iss・aud・exp の
 * いずれかが欠けたトークンを 1 つでも通すと管理者なりすましが成立する。
 * ここでは「通ってはいけないトークン」を網羅的に落とすことを主眼に置く。
 */
class IdTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://tenant.example.com';
    private const AUDIENCE = 'ec-test-client-id';

    /**
     * @var RsaKeyFixture
     */
    private static $key;

    /**
     * @var RsaKeyFixture 別テナント（攻撃者）の鍵
     */
    private static $otherKey;

    public static function setUpBeforeClass(): void
    {
        // 鍵生成は遅いのでクラス単位で 1 度だけ行う
        self::$key = new RsaKeyFixture('kid-primary');
        self::$otherKey = new RsaKeyFixture('kid-attacker');
    }

    public function testValidTokenIsAccepted(): void
    {
        $token = self::$key->sign($this->claims());

        $payload = $this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE);

        self::assertIsArray($payload);
        self::assertSame('b2b-subject-uuid', $payload['sub']);
    }

    public function testTrailingSlashInConfiguredIssuerIsTolerated(): void
    {
        $token = self::$key->sign($this->claims());

        $payload = $this->createVerifier()->verify($token, self::ISSUER.'/', self::AUDIENCE);

        self::assertIsArray($payload);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $tampered = RsaKeyFixture::tamperSignature(self::$key->sign($this->claims()));

        self::assertNull($this->createVerifier()->verify($tampered, self::ISSUER, self::AUDIENCE));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        // 正規のトークンの sub だけを別の管理者のものに差し替える（署名は元のまま）
        $token = self::$key->sign($this->claims());
        $parts = explode('.', $token);
        $payload = json_decode(RsaKeyFixture::base64UrlDecode($parts[1]), true);
        $payload['sub'] = 'another-admin-subject';
        $tampered = $parts[0].'.'.RsaKeyFixture::base64UrlEncode((string) json_encode($payload)).'.'.$parts[2];

        self::assertNull($this->createVerifier()->verify($tampered, self::ISSUER, self::AUDIENCE));
    }

    public function testTokenSignedByAnotherKeyIsRejected(): void
    {
        // 攻撃者が自分の鍵で署名し、kid だけ正規のものに偽装したケース
        $token = self::$otherKey->sign($this->claims(), ['kid' => 'kid-primary']);

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAlgNoneIsRejected(): void
    {
        $token = self::$key->forge($this->claims(), ['alg' => 'none'], '');

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testHmacAlgorithmIsRejected(): void
    {
        // 公開鍵を HMAC の鍵として使う alg confusion の典型パターン
        $jwk = self::$key->jwk();
        $payloadSegment = RsaKeyFixture::base64UrlEncode((string) json_encode($this->claims()));
        $headerSegment = RsaKeyFixture::base64UrlEncode((string) json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
            'kid' => 'kid-primary',
        ]));
        $signature = hash_hmac('sha256', $headerSegment.'.'.$payloadSegment, $jwk['n'], true);
        $token = $headerSegment.'.'.$payloadSegment.'.'.RsaKeyFixture::base64UrlEncode($signature);

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testIssuerMismatchIsRejected(): void
    {
        $token = self::$key->sign($this->claims(['iss' => 'https://evil.example.com']));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAudienceMismatchIsRejected(): void
    {
        $token = self::$key->sign($this->claims(['aud' => 'another-client-id']));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAudienceAsArrayIsAccepted(): void
    {
        $token = self::$key->sign($this->claims(['aud' => ['another-client-id', self::AUDIENCE]]));

        self::assertIsArray($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testMissingExpIsRejected(): void
    {
        $claims = $this->claims();
        unset($claims['exp']);
        $token = self::$key->sign($claims);

        // 修正前は exp が無いトークンが通っていた（EcAuthDocs #101）
        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = self::$key->sign($this->claims(['exp' => 1_700_000_000 - 3600]));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testTokenWithFutureNbfIsRejected(): void
    {
        $token = self::$key->sign($this->claims(['nbf' => 1_700_000_000 + 3600]));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testTokenIssuedInTheFutureIsRejected(): void
    {
        $token = self::$key->sign($this->claims(['iat' => 1_700_000_000 + 3600]));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testMissingSubIsRejected(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);
        $token = self::$key->sign($claims);

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $verifier = $this->createVerifier();

        self::assertNull($verifier->verify('not-a-jwt', self::ISSUER, self::AUDIENCE));
        self::assertNull($verifier->verify('a.b', self::ISSUER, self::AUDIENCE));
        self::assertNull($verifier->verify('', self::ISSUER, self::AUDIENCE));
    }

    public function testVerificationIsSkippedWhenIssuerOrAudienceIsNotConfigured(): void
    {
        $token = self::$key->sign($this->claims());
        $verifier = $this->createVerifier();

        self::assertNull($verifier->verify($token, '', self::AUDIENCE));
        self::assertNull($verifier->verify($token, self::ISSUER, ''));
    }

    public function testUnavailableJwksIsRejected(): void
    {
        $token = self::$key->sign($this->claims());
        $verifier = new TestableIdTokenVerifier(new FakeJwksProvider(null), new NullLogger());

        self::assertNull($verifier->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testRotatedKeyIsPickedUpByForcedRefresh(): void
    {
        $rotatedKey = new RsaKeyFixture('kid-rotated');
        $token = $rotatedKey->sign($this->claims());

        // キャッシュ済み JWKS には新しい kid が無く、再取得で見つかる状況
        $provider = new FakeJwksProvider([self::$key->jwk()], [$rotatedKey->jwk()]);
        $verifier = new TestableIdTokenVerifier($provider, new NullLogger());

        self::assertIsArray($verifier->verify($token, self::ISSUER, self::AUDIENCE));
        self::assertSame([false, true], $provider->calls);
    }

    public function testSignatureFailureDoesNotTriggerAnotherFetch(): void
    {
        // 鍵は見つかるが署名が不正なケースでは再取得しない（無駄な外部リクエストを避ける）
        $token = self::$otherKey->sign($this->claims(), ['kid' => 'kid-primary']);
        $provider = new FakeJwksProvider([self::$key->jwk()]);
        $verifier = new TestableIdTokenVerifier($provider, new NullLogger());

        self::assertNull($verifier->verify($token, self::ISSUER, self::AUDIENCE));
        self::assertSame([false], $provider->calls);
    }

    public function testKeyWithoutKidIsUsedOnlyWhenUnambiguous(): void
    {
        $token = self::$key->signWithExactHeader($this->claims(), ['alg' => 'RS256', 'typ' => 'JWT']);

        $jwk = self::$key->jwk();
        unset($jwk['kid']);
        $verifier = new TestableIdTokenVerifier(new FakeJwksProvider([$jwk]), new NullLogger());
        self::assertIsArray($verifier->verify($token, self::ISSUER, self::AUDIENCE));

        // 候補が複数ある場合は kid 無しでは鍵を特定できないので拒否する
        $otherJwk = self::$otherKey->jwk();
        unset($otherJwk['kid']);
        $ambiguous = new TestableIdTokenVerifier(new FakeJwksProvider([$jwk, $otherJwk]), new NullLogger());
        self::assertNull($ambiguous->verify($token, self::ISSUER, self::AUDIENCE));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function claims(array $override = []): array
    {
        return array_merge([
            'sub' => 'b2b-subject-uuid',
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => 1_700_000_000 - 60,
            'nbf' => 1_700_000_000 - 60,
            'exp' => 1_700_000_000 + 3600,
            'jti' => 'token-id',
        ], $override);
    }

    private function createVerifier(): TestableIdTokenVerifier
    {
        return new TestableIdTokenVerifier(
            new FakeJwksProvider([self::$key->jwk()]),
            new NullLogger(),
        );
    }
}

/**
 * 時刻を固定して検証するためのサブクラス。
 */
class TestableIdTokenVerifier extends IdTokenVerifier
{
    protected function now(): int
    {
        return 1_700_000_000;
    }
}
