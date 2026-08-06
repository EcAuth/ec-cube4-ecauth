<?php

namespace Plugin\EcAuthLogin43\Service;

/**
 * EcAuth Base URL が許可されたホストを指しているかを検証する。
 *
 * Base URL はトークン交換先および JWKS 取得先になるため、設定汚染や
 * client-resolve 応答の改竄によって攻撃者のホストに向けられると、
 * id_token の署名検証ごと攻撃者の鍵で成立してしまう。信頼の起点として
 * ホストを許可リストで縛る。
 *
 * 許可リストは環境変数 ECAUTH_ALLOWED_HOSTS（カンマ区切り）で上書きできる。
 * 各エントリの書式:
 *
 *   .example.com      … example.com のサブドメインすべて（apex は含まない）
 *   example.com       … ホスト完全一致
 *   example.com:8081  … ホストとポートの完全一致
 *   http://localhost:8080 … 既定では https のみ許可するため、http を許す場合はスキームを明示する
 *
 * ポートを省略したエントリはスキームの既定ポート（https なら 443）のみを許可する。
 * 既定以外のポートを使う場合は host:port で明示すること。
 *
 * 許可リストが空の場合はすべて拒否する（fail-closed）。
 *
 * ワイルドカードは可能な限り狭くすること。例えば .azurewebsites.net のような
 * 共有ホスティングのサフィックスを許可すると、そのサービスの全利用者
 * （＝無関係な第三者）を信頼することになり、この検証の意味が失われる。
 */
class BaseUrlValidator
{
    /**
     * @var array<int, array{scheme: string|null, host: string, port: int|null, suffix: bool}>
     */
    private $allowedHosts;

    public function __construct(string $allowedHosts)
    {
        $this->allowedHosts = $this->parseAllowedHosts($allowedHosts);
    }

    /**
     * Base URL を検証し、正規化した URL を返す。許可されない場合は null。
     */
    public function normalize(?string $baseUrl): ?string
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return null;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        // 認証情報付き URL (https://evil@example.com) やパス・クエリ付きは受け付けない。
        // EcAuth の Base URL は "{scheme}://{host}" の形に限られる。
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '') {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $this->normalizePort($scheme, isset($parts['port']) ? $parts['port'] : null);

        if (!$this->matchesAllowedHost($scheme, $host, $port)) {
            return null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    public function isAllowed(?string $baseUrl): bool
    {
        return $this->normalize($baseUrl) !== null;
    }

    private function matchesAllowedHost(string $scheme, string $host, ?int $port): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            // スキームを明示していないエントリは https のみ許可する。
            $expectedScheme = $allowed['scheme'] ?? 'https';
            if ($scheme !== $expectedScheme) {
                continue;
            }

            // ポートを省略したエントリは「スキームの既定ポートのみ」を意味する。
            // 省略＝任意ポート許可にすると、許可ホスト上の別サービス（例
            // https://tenant.ec-auth.io:8443）まで信頼境界に入ってしまう。
            if ($allowed['port'] !== $port) {
                continue;
            }

            if ($allowed['suffix']) {
                $suffix = $allowed['host'];
                if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
                    return true;
                }
                continue;
            }

            if ($host === $allowed['host']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{scheme: string|null, host: string, port: int|null, suffix: bool}>
     */
    private function parseAllowedHosts(string $allowedHosts): array
    {
        $parsed = [];
        foreach (explode(',', $allowedHosts) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }

            $scheme = null;
            if (strpos($entry, '://') !== false) {
                [$scheme, $entry] = explode('://', $entry, 2);
                $entry = trim($entry);
                if ($entry === '' || ($scheme !== 'http' && $scheme !== 'https')) {
                    continue;
                }
            }

            $suffix = strpos($entry, '.') === 0;

            $port = null;
            // IPv6 リテラルは扱わない。ポート指定は "host:port" のみ。
            if (preg_match('/^(.+):(\d+)$/', $entry, $matches) === 1) {
                $entry = $matches[1];
                $port = (int) $matches[2];
            }

            if ($entry === '.') {
                continue;
            }

            $parsed[] = [
                'scheme' => $scheme,
                'host' => $entry,
                'port' => $this->normalizePort($scheme === null ? 'https' : $scheme, $port),
                'suffix' => $suffix,
            ];
        }

        return $parsed;
    }

    /**
     * スキームの既定ポートを明示指定した場合は、省略時と同じ「null」に正規化する。
     * https://example.com と https://example.com:443 を別物として扱わないため。
     */
    private function normalizePort(string $scheme, ?int $port): ?int
    {
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return null;
        }

        return $port;
    }
}
