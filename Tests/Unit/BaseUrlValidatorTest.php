<?php

namespace Plugin\EcAuthLogin43\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\EcAuthLogin43\Service\BaseUrlValidator;

/**
 * EcAuthDocs #101: Base URL はトークン交換先かつ JWKS 取得先になるため、
 * 許可されないホストを 1 つでも通すと id_token の検証ごと攻撃者に握られる。
 */
class BaseUrlValidatorTest extends TestCase
{
    public function testSubdomainSuffixIsAllowed(): void
    {
        $validator = new BaseUrlValidator('.ec-auth.io');

        self::assertSame('https://tenant.ec-auth.io', $validator->normalize('https://tenant.ec-auth.io'));
        self::assertSame('https://tenant.ec-auth.io', $validator->normalize('https://tenant.ec-auth.io/'));
    }

    public function testApexIsNotMatchedBySuffixEntry(): void
    {
        $validator = new BaseUrlValidator('.ec-auth.io');

        self::assertNull($validator->normalize('https://ec-auth.io'));
    }

    public function testSuffixEntryDoesNotMatchLookalikeDomain(): void
    {
        $validator = new BaseUrlValidator('.ec-auth.io');

        // "evil-ec-auth.io" のような部分一致で通ってはいけない
        self::assertNull($validator->normalize('https://tenant.evil-ec-auth.io'));
        self::assertNull($validator->normalize('https://ec-auth.io.evil.example'));
    }

    public function testExactHostEntry(): void
    {
        $validator = new BaseUrlValidator('ecauth.example.com');

        self::assertSame('https://ecauth.example.com', $validator->normalize('https://ecauth.example.com'));
        self::assertNull($validator->normalize('https://sub.ecauth.example.com'));
    }

    public function testHttpIsRejectedUnlessSchemeIsExplicit(): void
    {
        $httpsOnly = new BaseUrlValidator('localhost');
        self::assertNull($httpsOnly->normalize('http://localhost'));

        $withHttp = new BaseUrlValidator('http://localhost:8080');
        self::assertSame('http://localhost:8080', $withHttp->normalize('http://localhost:8080'));
    }

    public function testPortMustMatchWhenSpecified(): void
    {
        $validator = new BaseUrlValidator('localhost:8081');

        self::assertSame('https://localhost:8081', $validator->normalize('https://localhost:8081'));
        self::assertNull($validator->normalize('https://localhost:9999'));
        self::assertNull($validator->normalize('https://localhost'));
    }

    public function testEntryWithoutPortAllowsOnlyTheDefaultPort(): void
    {
        $validator = new BaseUrlValidator('.ec-auth.io');

        // 許可ホスト上の別サービスまで信頼境界に入れない
        self::assertNull($validator->normalize('https://tenant.ec-auth.io:8443'));
        // 既定ポートの明示指定は省略時と同一視し、正規化して落とす
        self::assertSame('https://tenant.ec-auth.io', $validator->normalize('https://tenant.ec-auth.io:443'));
    }

    public function testExplicitDefaultPortInAllowListIsEquivalentToOmittingIt(): void
    {
        $validator = new BaseUrlValidator('ecauth.example.com:443');

        self::assertSame('https://ecauth.example.com', $validator->normalize('https://ecauth.example.com'));
        self::assertSame('https://ecauth.example.com', $validator->normalize('https://ecauth.example.com:443'));
        self::assertNull($validator->normalize('https://ecauth.example.com:8443'));
    }

    public function testMultipleEntriesAreSupported(): void
    {
        // 共有ホスティングのサフィックス（.azurewebsites.net 等）は例に使わない。
        // 推奨設定と誤読されうるため（クラス docblock 参照）。
        $validator = new BaseUrlValidator('.ec-auth.io, ecauth-staging.example.net, localhost:8081');

        self::assertNotNull($validator->normalize('https://tenant.ec-auth.io'));
        self::assertNotNull($validator->normalize('https://ecauth-staging.example.net'));
        self::assertNotNull($validator->normalize('https://localhost:8081'));
        self::assertNull($validator->normalize('https://evil.example.com'));
    }

    public function testEmptyAllowListRejectsEverything(): void
    {
        $validator = new BaseUrlValidator('');

        self::assertNull($validator->normalize('https://tenant.ec-auth.io'));
    }

    public function testUrlsWithCredentialsOrPathAreRejected(): void
    {
        $validator = new BaseUrlValidator('.ec-auth.io');

        self::assertNull($validator->normalize('https://evil@tenant.ec-auth.io'));
        self::assertNull($validator->normalize('https://tenant.ec-auth.io/path'));
        self::assertNull($validator->normalize('https://tenant.ec-auth.io?a=1'));
        self::assertNull($validator->normalize('https://tenant.ec-auth.io#f'));
    }

    public function testMalformedInputIsRejected(): void
    {
        $validator = new BaseUrlValidator('.ec-auth.io');

        self::assertNull($validator->normalize(null));
        self::assertNull($validator->normalize(''));
        self::assertNull($validator->normalize('   '));
        self::assertNull($validator->normalize('tenant.ec-auth.io'));
        self::assertNull($validator->normalize('javascript:alert(1)'));
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        $validator = new BaseUrlValidator('.EC-AUTH.IO');

        self::assertSame('https://tenant.ec-auth.io', $validator->normalize('HTTPS://Tenant.Ec-Auth.IO'));
    }

    public function testIsAllowedMirrorsNormalize(): void
    {
        $validator = new BaseUrlValidator('.ec-auth.io');

        self::assertTrue($validator->isAllowed('https://tenant.ec-auth.io'));
        self::assertFalse($validator->isAllowed('https://evil.example.com'));
    }
}
