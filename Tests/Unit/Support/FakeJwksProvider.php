<?php

namespace Plugin\EcAuthLogin43\Tests\Unit\Support;

use Plugin\EcAuthLogin43\Service\JwksProviderInterface;

/**
 * JWKS 取得を差し替えるテストダブル。
 *
 * $refreshedKeys を設定すると、forceRefresh = true のときだけ別の鍵セットを返す。
 * 鍵ローテーション時の再取得挙動を検証するために使う。
 */
class FakeJwksProvider implements JwksProviderInterface
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    public $keys;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    public $refreshedKeys;

    /**
     * getJwks() が forceRefresh に何を渡されたかの記録。
     *
     * @var array<int, bool>
     */
    public $calls = [];

    /**
     * @param array<int, array<string, mixed>>|null $keys
     * @param array<int, array<string, mixed>>|null $refreshedKeys
     */
    public function __construct(?array $keys, ?array $refreshedKeys = null)
    {
        $this->keys = $keys;
        $this->refreshedKeys = $refreshedKeys;
    }

    /**
     * {@inheritdoc}
     */
    public function getJwks(string $baseUrl, bool $forceRefresh = false): ?array
    {
        $this->calls[] = $forceRefresh;

        if ($forceRefresh && $this->refreshedKeys !== null) {
            return $this->refreshedKeys;
        }

        return $this->keys;
    }
}
