<?php

namespace Plugin\EcAuthLogin43\Service;

/**
 * EcAuth の B2B パスキー登録（register/options）に渡す external_id を組み立てる。
 *
 * EcAuth は external_id の中身を解釈しない。発行元（client_id）ごとの名前空間の下で
 * ハッシュ化して保持し、同じ値が来れば同じ管理者として解決する。呼び出し側に要求されるのは
 * 不変性・一意性・再利用禁止の 3 点（EcAuthDocs#110）。
 *
 * 1.1.0 以前のリリースでは dtb_member.login_id を送っていたが、login_id は管理画面から変更できるため
 * 恒久的なキーにならない（変更すると EcAuth 側では別人になり、プラグイン再インストール時に
 * 既存の B2BUser へ戻る復旧経路が壊れる）。dtb_member.member_id は採番後に変わらず
 * 再利用もされないので、こちらを使う。
 *
 * 接頭辞 "member:" を付けるのは、旧バージョンが送った login_id のハッシュと衝突させないため。
 * EC-CUBE は数字のみの login_id を許すので、素の member_id を送ると「login_id = "7" の管理者」と
 * 「member_id = 7 の管理者」が EcAuth 上で同一の値になり、登録が 409 で弾かれたり、
 * 再インストール後に別人へ解決されたりする。
 *
 * 2 系プラグイン（EcAuthLogin2）と 4.0/4.1 系（EcAuthLogin40）も同じ形式を使う。
 * 形式を変えると EcAuth に保持済みの identity と一致しなくなり、再び移行が必要になる。
 *
 * 認証器に表示されるアカウント名はここでは扱わない。register/options の user_name に
 * login_id を別途渡す（EcAuth#544）。
 */
class B2BExternalId
{
    public const PREFIX = 'member:';

    /**
     * @param int $memberId dtb_member.member_id
     */
    public static function forMember(int $memberId): string
    {
        if ($memberId <= 0) {
            throw new \InvalidArgumentException('member_id must be a positive integer.');
        }

        return self::PREFIX.$memberId;
    }
}
