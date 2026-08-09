<?php

namespace Plugin\EcAuthLogin43\Service;

/**
 * 設定画面で接続先テナント（client_id）が差し替えられたかを判定し、
 * それに伴う入力値の扱いを決める。
 *
 * EcAuth の B2BUser.Subject は Organization をまたいでグローバル一意なため、
 * 旧テナントで発番済みの ecauth_subject を残したまま client_id を差し替えると、
 * 新テナントへの登録が一意制約に阻まれ register/options が必ず 400 になる
 * （EcAuth/ec-cube4-ecauth#52）。その後始末を起動するかどうかの判断がここ。
 *
 * EC-CUBE / Symfony に依存しない純粋な判定のみを置く。判定の分岐が多く、
 * 誤ると「全管理者のパスキーを巻き添えでクリアする」方向に倒れるため、
 * ユニットテスト（Tests/Unit/TenantChangePolicyTest.php）で固定している。
 * EC-CUBE のカーネルを起動しない phpunit.xml.dist の対象に入れるための分離でもある。
 */
class TenantChangePolicy
{
    /**
     * 接続先テナント（client_id）が変わったかを判定する。
     *
     * - 初回登録（保存前の値が無い）では変更扱いにしない。まだどのテナントにも
     *   subject を登録していないため、後始末する対象が存在しない。
     * - 前後が同じなら変更ではない。設定画面は client_id 以外（rp_id 等）だけを
     *   変えて保存されることの方が多く、ここで誤判定すると全管理者の
     *   パスキーを巻き添えにする。
     * - 前後の空白差だけの違いも変更としない。保存側は trim 済みの値を渡すが、
     *   旧値は trim せず保存された可能性があるため、両方 trim して比べる。
     * - client_id は EcAuth が払い出す不透明な識別子なので、大文字小文字は区別する。
     */
    public function hasClientIdChanged(?string $previousClientId, ?string $newClientId): bool
    {
        $previous = trim((string) $previousClientId);
        $new = trim((string) $newClientId);

        if ($previous === '') {
            return false;
        }

        return $previous !== $new;
    }

    /**
     * 接続先が変わるとき、フォームの Base URL 入力を捨てて再解決すべきかを判定する。
     *
     * 設定画面は Base URL 欄に保存済みの値を事前入力する。Client ID だけを
     * 書き換えて保存すると、欄に残った「前の接続先の URL」が入力値として扱われ、
     * 新しい client_id と前のテナントの Base URL という不整合な組み合わせが
     * 保存されてしまう。事前入力のまま（＝保存済みと同値）なら触っていないと
     * みなして捨て、client_id からの再解決に委ねる。
     *
     * 逆に保存済みと違う値が入っていれば管理者が意図して指定したものなので
     * 尊重する（開発・ステージングの手動指定を潰さないため）。
     *
     * ここで true を返しても「その URL を使わない」とは限らない。呼び出し側は
     * 再解決に失敗したときに捨てた値へフォールバックする。同じ EcAuth を複数
     * テナントで共有し URL を手動指定している環境では、捨てたまま弾くと接続先を
     * 切り替える手段が無くなるため（#59 レビュー指摘）。あくまで
     * 「解決できるなら新しい client_id 由来の URL を優先する」という優先順位付け。
     */
    public function shouldDiscardBaseUrlInput(bool $clientIdChanged, ?string $inputBaseUrl, ?string $savedBaseUrl): bool
    {
        if (!$clientIdChanged) {
            return false;
        }

        $input = trim((string) $inputBaseUrl);
        if ($input === '') {
            // もともと未入力なら呼び出し側が従来どおり再解決する。捨てるものが無い。
            return false;
        }

        return $input === trim((string) $savedBaseUrl);
    }
}
