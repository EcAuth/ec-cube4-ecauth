<?php

namespace Plugin\EcAuthLogin43\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\EcAuthLogin43\Service\B2BExternalId;

/**
 * EcAuthDocs#110: register/options に渡す external_id の形式を固定する。
 *
 * EcAuth はこの値をハッシュ化して identity として保持するため、形式が変わると
 * 既存の管理者が別人として扱われる。2 系 / 4.0 系プラグインとも同じ形式にする。
 */
class B2BExternalIdTest extends TestCase
{
    public function testFormatIsMemberPrefixFollowedByMemberId(): void
    {
        self::assertSame('member:1', B2BExternalId::forMember(1));
        self::assertSame('member:1234567', B2BExternalId::forMember(1234567));
    }

    public function testPrefixDoesNotCollideWithNumericLoginId(): void
    {
        // 旧バージョンは login_id をそのまま送っていた。数字のみの login_id "7" と
        // member_id = 7 が同じ値にならないことが接頭辞の存在理由。
        self::assertNotSame('7', B2BExternalId::forMember(7));
        self::assertStringStartsWith(B2BExternalId::PREFIX, B2BExternalId::forMember(7));
    }

    /**
     * @dataProvider invalidMemberIds
     */
    public function testRejectsNonPositiveMemberId(int $memberId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        B2BExternalId::forMember($memberId);
    }

    /**
     * @return array<string, array{int}>
     */
    public function invalidMemberIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }
}
