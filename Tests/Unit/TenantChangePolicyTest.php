<?php

namespace Plugin\EcAuthLogin43\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\EcAuthLogin43\Service\TenantChangePolicy;

/**
 * EcAuth/ec-cube4-ecauth#52: 接続先テナント（client_id）を差し替えたのに
 * dtb_member.ecauth_subject が残っていると、EcAuth 側の B2BUser.Subject が
 * Organization をまたいでグローバル一意なせいで register/options が必ず 400 になる。
 *
 * 誤って「変更あり」と判定すると全管理者のパスキーを巻き添えでクリアするため、
 * 判定の分岐をここで固定する。
 */
class TenantChangePolicyTest extends TestCase
{
    /**
     * @var TenantChangePolicy
     */
    private $policy;

    protected function setUp(): void
    {
        $this->policy = new TenantChangePolicy();
    }

    // ------------------------------------------------------------------
    // いつ「接続先が変わった」とみなすか
    // ------------------------------------------------------------------

    public function testInitialSaveIsNotTreatedAsChange(): void
    {
        // 初回登録。まだどのテナントにも subject を登録していないので後始末は不要。
        self::assertFalse($this->policy->hasClientIdChanged('', 'ec-shop-1111'));
        self::assertFalse($this->policy->hasClientIdChanged(null, 'ec-shop-1111'));
    }

    public function testUnchangedClientIdIsNotAChange(): void
    {
        // client_id 以外（rp_id 等）だけを変えて保存する方が普通の操作なので、
        // ここで誤判定すると全管理者のパスキーを巻き添えにする。
        self::assertFalse($this->policy->hasClientIdChanged('ec-shop-1111', 'ec-shop-1111'));
    }

    public function testWhitespaceOnlyDifferenceIsNotAChange(): void
    {
        // 保存側は trim 済みの値を渡すが、旧値は trim せず保存された可能性がある。
        // 空白差だけで全管理者のパスキーを無効化してはいけない。
        self::assertFalse($this->policy->hasClientIdChanged(' ec-shop-1111 ', 'ec-shop-1111'));
        self::assertFalse($this->policy->hasClientIdChanged('ec-shop-1111', "ec-shop-1111\n"));
    }

    public function testDifferentClientIdIsAChange(): void
    {
        // #52 の本題。テスト用テナントから本番用テナントへの差し替え。
        self::assertTrue($this->policy->hasClientIdChanged('ec-shop-1111', 'ec-shop-2222'));
    }

    public function testClientIdIsComparedCaseSensitively(): void
    {
        // client_id は EcAuth が払い出す不透明な識別子。大文字小文字が違えば別テナント。
        self::assertTrue($this->policy->hasClientIdChanged('ec-shop-1111', 'EC-SHOP-1111'));
    }

    public function testBlankedOutClientIdIsAChange(): void
    {
        // ConfigType の NotBlank があるため通常は起きないが、
        // 接続先が失われた状態で古い subject を残す理由も無い。
        self::assertTrue($this->policy->hasClientIdChanged('ec-shop-1111', ''));
        self::assertTrue($this->policy->hasClientIdChanged('ec-shop-1111', null));
    }

    // ------------------------------------------------------------------
    // 接続先が変わるとき、事前入力の Base URL を捨てるか
    //
    // 設定画面は Base URL 欄に保存済みの値を事前入力する。Client ID だけを
    // 書き換えて保存すると、欄に残った前の接続先の URL が「入力あり」と見なされ、
    // 「新しい client_id + 前の接続先の Base URL」が保存されてしまう。
    // ------------------------------------------------------------------

    public function testBaseUrlInputIsKeptWhenClientIdUnchanged(): void
    {
        // Client ID が同じなら、Base URL 欄の値は管理者の意思として尊重する。
        self::assertFalse($this->policy->shouldDiscardBaseUrlInput(
            false,
            'https://old.ec-auth.io',
            'https://old.ec-auth.io',
        ));
    }

    public function testUntouchedBaseUrlIsDiscardedWhenClientIdChanged(): void
    {
        // 事前入力のまま（保存済みの値と同一）＝ 触っていないので捨てて再解決する。
        self::assertTrue($this->policy->shouldDiscardBaseUrlInput(
            true,
            'https://old.ec-auth.io',
            'https://old.ec-auth.io',
        ));
        // 前後の空白差は「触った」うちに入らない。
        self::assertTrue($this->policy->shouldDiscardBaseUrlInput(
            true,
            ' https://old.ec-auth.io ',
            'https://old.ec-auth.io',
        ));
    }

    public function testExplicitlyTypedBaseUrlIsKeptWhenClientIdChanged(): void
    {
        // 保存済みと違う値を打っているなら、管理者が意図して指定したもの。
        // 開発・ステージングの手動指定を潰さないため、こちらは尊重する。
        self::assertFalse($this->policy->shouldDiscardBaseUrlInput(
            true,
            'https://new.ec-auth.io',
            'https://old.ec-auth.io',
        ));
    }

    public function testEmptyBaseUrlInputIsNotTreatedAsDiscardable(): void
    {
        // もともと未入力なら呼び出し側が従来どおり解決する。捨てるものが無い。
        self::assertFalse($this->policy->shouldDiscardBaseUrlInput(true, '', 'https://old.ec-auth.io'));
        self::assertFalse($this->policy->shouldDiscardBaseUrlInput(true, null, null));
    }

    public function testFirstTimeSaveWithTypedBaseUrlIsKept(): void
    {
        // 初回登録は hasClientIdChanged() が false になるため、
        // 保存済みが空でも入力値は捨てられない。
        self::assertFalse($this->policy->shouldDiscardBaseUrlInput(false, 'https://new.ec-auth.io', ''));
    }
}
