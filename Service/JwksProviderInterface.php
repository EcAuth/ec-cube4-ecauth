<?php

namespace Plugin\EcAuthLogin43\Service;

/**
 * EcAuth の JWKS (JSON Web Key Set) を取得する抽象。
 *
 * 実装は取得結果をキャッシュしてよいが、$forceRefresh = true の場合は
 * キャッシュを無視して再取得しなければならない（鍵ローテーション追従のため）。
 */
interface JwksProviderInterface
{
    /**
     * @param string $baseUrl EcAuth の Base URL（末尾スラッシュなし）
     * @param bool $forceRefresh キャッシュを無視して再取得する
     *
     * @return array<int, array<string, mixed>>|null JWK の配列。取得失敗時は null
     */
    public function getJwks(string $baseUrl, bool $forceRefresh = false): ?array;
}
