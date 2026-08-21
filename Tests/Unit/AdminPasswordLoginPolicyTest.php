<?php

namespace Plugin\EcAuthLogin43\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\EcAuthLogin43\Service\AdminPasswordLoginPolicy;

/**
 * 管理画面のパスワード認証を無効化する判定。
 *
 * 誤ると倒れる方向が両側にあるため、判定表をここで固定する。
 *
 * - 広すぎる: EC サイトのフロント会員（customer ファイアウォール）まで巻き添えで
 *   ログインできなくなる。Symfony は CheckPassportEvent のグローバルリスナーを
 *   全ファイアウォールのディスパッチャへ複製するため、ファイアウォールで絞らないと
 *   実際にこうなる。
 * - 狭すぎる: 管理画面のパスワード認証を塞げていないのに、設定画面には「無効」と
 *   表示される。守られているつもりで守られていない状態になる。
 */
class AdminPasswordLoginPolicyTest extends TestCase
{
    public function testDisabledFlagIsExposedAsIs(): void
    {
        // 設定画面のステータス表示とログイン画面の案内が参照する。
        self::assertTrue((new AdminPasswordLoginPolicy(true))->isDisabled());
        self::assertFalse((new AdminPasswordLoginPolicy(false))->isDisabled());
    }

    public function testRejectsAdminPasswordLoginWhenDisabled(): void
    {
        // 本題。admin ファイアウォールでのパスワード認証だけを拒否する。
        $policy = new AdminPasswordLoginPolicy(true);

        self::assertTrue($policy->shouldReject(true, true));
    }

    public function testKeepsAdminPasswordLoginWhenEnabled(): void
    {
        // 環境変数を戻せばパスワードでログインできる。パスキー紛失時の復旧手段が
        // これしか無いため、既定（未設定）で塞いではいけない。
        $policy = new AdminPasswordLoginPolicy(false);

        self::assertFalse($policy->shouldReject(true, true));
    }

    public function testNeverRejectsOutsideAdminFirewall(): void
    {
        // EC サイトのフロント会員ログイン。無効化中でも絶対に塞がない。
        $policy = new AdminPasswordLoginPolicy(true);

        self::assertFalse($policy->shouldReject(true, false));
    }

    public function testNeverRejectsWithoutPasswordCredentials(): void
    {
        // 無効化するのはパスワード認証であって、ログインそのものではない。
        // パスワード以外の資格情報で認証しようとしている経路は通す。
        $policy = new AdminPasswordLoginPolicy(true);

        self::assertFalse($policy->shouldReject(false, true));
        self::assertFalse($policy->shouldReject(false, false));
    }
}
