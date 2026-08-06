<?php

namespace Plugin\EcAuthLogin43\Service;

use Psr\Log\LoggerInterface;

/**
 * EcAuth が発行した id_token (JWT) を検証する。
 *
 * 本クラスは「管理者セッションを確立してよいか」の判断の起点になるため、
 * 検証に少しでも失敗した場合は必ず null を返す（fail-closed）。
 *
 * 検証内容:
 *   1. JWT ヘッダの alg が RS256 であること（alg=none / HS256 への差し替え拒否）
 *   2. JWKS ({base_url}/.well-known/jwks.json) の公開鍵による RS256 署名検証
 *   3. iss が設定済み Base URL と完全一致すること
 *   4. aud が自身の client_id を含むこと、および azp（あれば）が自身であること
 *   5. exp が存在し、有効期限内であること（exp 欠落トークンは拒否）
 *   6. nbf / iat が存在する場合、それらが未来でないこと
 *
 * EcAuth 側の実装は RS256 固定（TokenService）、issuer は "{scheme}://{host}"
 * （IssuerResolver、末尾スラッシュなし）、aud は client_id。
 *
 * 署名検証そのものは OpenSSL に委譲し、本クラスでは JWK (n, e) から
 * SubjectPublicKeyInfo の DER を組み立てて PEM 化する処理のみを持つ。
 */
class IdTokenVerifier
{
    /**
     * 許容する署名アルゴリズム。EcAuth は RS256 固定で発行する。
     * ここを緩めると alg confusion 攻撃の余地が生まれるため、他の値は受け付けない。
     */
    private const REQUIRED_ALG = 'RS256';

    /**
     * 時刻クレーム比較時に許容するずれ（秒）。EC-CUBE サーバーと EcAuth の
     * 時刻同期ずれを吸収するための最小限の値。
     */
    private const CLOCK_SKEW = 60;

    /**
     * OID 1.2.840.113549.1.1.1 (rsaEncryption) の DER エンコード。
     */
    private const OID_RSA_ENCRYPTION = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    /**
     * @var JwksProviderInterface
     */
    private $jwksProvider;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(JwksProviderInterface $jwksProvider, LoggerInterface $logger)
    {
        $this->jwksProvider = $jwksProvider;
        $this->logger = $logger;
    }

    /**
     * id_token を検証し、検証済みのペイロード（クレーム）を返す。
     *
     * @param string $idToken 検証対象の id_token (JWT)
     * @param string $expectedIssuer 期待する iss（＝ EcAuth Base URL、末尾スラッシュなし）
     * @param string $expectedAudience 期待する aud（＝ 自身の client_id）
     *
     * @return array<string, mixed>|null 検証成功時はペイロード、失敗時は null
     */
    public function verify(string $idToken, string $expectedIssuer, string $expectedAudience): ?array
    {
        $expectedIssuer = rtrim($expectedIssuer, '/');
        if ($expectedIssuer === '' || $expectedAudience === '') {
            $this->logger->error('ID token verification skipped: issuer or audience is not configured');

            return null;
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            $this->logger->warning('ID token is not a well-formed JWT');

            return null;
        }

        $header = $this->decodeJsonSegment($parts[0]);
        $payload = $this->decodeJsonSegment($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);

        if ($header === null || $payload === null || $signature === null || $signature === '') {
            $this->logger->warning('ID token segments could not be decoded');

            return null;
        }

        $alg = isset($header['alg']) && is_string($header['alg']) ? $header['alg'] : '';
        if (!hash_equals(self::REQUIRED_ALG, $alg)) {
            // alg=none や HS256 への差し替えはここで落とす
            $this->logger->warning('ID token uses an unsupported signing algorithm', [
                'alg' => $alg,
            ]);

            return null;
        }

        $kid = isset($header['kid']) && is_string($header['kid']) && $header['kid'] !== ''
            ? $header['kid']
            : null;

        $signingInput = $parts[0].'.'.$parts[1];
        if (!$this->verifySignature($signingInput, $signature, $expectedIssuer, $kid)) {
            return null;
        }

        if (!$this->validateClaims($payload, $expectedIssuer, $expectedAudience)) {
            return null;
        }

        if (!isset($payload['sub']) || !is_string($payload['sub']) || $payload['sub'] === '') {
            $this->logger->warning('ID token has no usable sub claim');

            return null;
        }

        return $payload;
    }

    /**
     * 現在時刻。テストから差し替えられるように分離している。
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * JWKS の公開鍵で署名を検証する。
     */
    private function verifySignature(string $signingInput, string $signature, string $issuer, ?string $kid): bool
    {
        $jwks = $this->jwksProvider->getJwks($issuer, false);
        $key = $jwks === null ? null : $this->selectKey($jwks, $kid);

        if ($key === null) {
            // 鍵が見つからないのはキャッシュが古い（鍵ローテーション直後）可能性がある。
            // 署名検証の失敗ではないため、ここでだけ強制再取得を 1 度試みる。
            $jwks = $this->jwksProvider->getJwks($issuer, true);
            $key = $jwks === null ? null : $this->selectKey($jwks, $kid);
        }

        if ($key === null) {
            $this->logger->warning('No matching JWK found for ID token', [
                'kid' => $kid,
            ]);

            return false;
        }

        $modulus = $this->base64UrlDecode((string) $key['n']);
        $exponent = $this->base64UrlDecode((string) $key['e']);
        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            $this->logger->error('JWK contains a malformed RSA public key');

            return false;
        }

        $publicKey = openssl_pkey_get_public($this->rsaPublicKeyToPem($modulus, $exponent));
        if ($publicKey === false) {
            $this->logger->error('Failed to build an OpenSSL public key from JWK');

            return false;
        }

        $result = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            $this->logger->warning('ID token signature verification failed', [
                'openssl_verify' => $result,
            ]);

            return false;
        }

        return true;
    }

    /**
     * 署名検証に使える JWK を選択する。
     *
     * kid がトークンヘッダにある場合は kid 一致を必須とする。kid が無い場合に限り、
     * 候補が 1 件だけならそれを使う（EcAuth 側の鍵ローテーション未実装時の互換措置）。
     *
     * @param array<int, array<string, mixed>> $jwks
     *
     * @return array<string, mixed>|null
     */
    private function selectKey(array $jwks, ?string $kid): ?array
    {
        $candidates = [];
        foreach ($jwks as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (!isset($key['kty']) || $key['kty'] !== 'RSA') {
                continue;
            }
            // use / alg は省略可。指定がある場合のみ厳格に一致を要求する。
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            if (isset($key['alg']) && $key['alg'] !== self::REQUIRED_ALG) {
                continue;
            }
            if (!isset($key['n'], $key['e']) || !is_string($key['n']) || !is_string($key['e'])) {
                continue;
            }
            $candidates[] = $key;
        }

        if ($kid !== null) {
            foreach ($candidates as $key) {
                if (isset($key['kid']) && is_string($key['kid']) && hash_equals($key['kid'], $kid)) {
                    return $key;
                }
            }

            return null;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * iss / aud / exp / nbf / iat を検証する。
     *
     * @param array<string, mixed> $payload
     */
    private function validateClaims(array $payload, string $expectedIssuer, string $expectedAudience): bool
    {
        // OIDC Core 3.1.3.7 (2): iss は完全一致で比較する。末尾スラッシュ等を
        // 吸収してしまうと、末尾スラッシュだけが異なる別 issuer のトークンを
        // 受け入れてしまう。設定値側は BaseUrlValidator で正規化済み。
        $issuer = isset($payload['iss']) && is_string($payload['iss']) ? $payload['iss'] : '';
        if (!hash_equals($expectedIssuer, $issuer)) {
            $this->logger->warning('ID token issuer mismatch');

            return false;
        }

        $audience = $payload['aud'] ?? null;
        if (!$this->audienceMatches($audience, $expectedAudience)) {
            $this->logger->warning('ID token audience mismatch');

            return false;
        }

        if (!$this->authorizedPartyMatches($payload, $audience, $expectedAudience)) {
            return false;
        }

        $now = $this->now();

        // exp は必須。欠落トークンを通すと期限切れトークンの再利用を許してしまう。
        if (!isset($payload['exp']) || !$this->isTimestamp($payload['exp'])) {
            $this->logger->warning('ID token has no valid exp claim');

            return false;
        }
        if ((int) $payload['exp'] <= $now - self::CLOCK_SKEW) {
            $this->logger->warning('ID token is expired');

            return false;
        }

        if (isset($payload['nbf']) && (!$this->isTimestamp($payload['nbf']) || (int) $payload['nbf'] > $now + self::CLOCK_SKEW)) {
            $this->logger->warning('ID token is not yet valid');

            return false;
        }

        if (isset($payload['iat']) && (!$this->isTimestamp($payload['iat']) || (int) $payload['iat'] > $now + self::CLOCK_SKEW)) {
            $this->logger->warning('ID token was issued in the future');

            return false;
        }

        return true;
    }

    /**
     * azp（Authorized Party）を検証する。
     *
     * OIDC Core 3.1.3.7 (4)(5):
     *   - aud が複数ある場合、azp の存在を確認しなければならない
     *   - azp がある場合、その値が自身の client_id と一致することを確認しなければならない
     *
     * aud に自分が含まれてさえいれば通す実装だと、別クライアント向けに発行された
     * （issuer の署名が正当な）トークンを使い回して管理者セッションを確立できてしまう。
     *
     * @param array<string, mixed> $payload
     * @param mixed $audience
     */
    private function authorizedPartyMatches(array $payload, $audience, string $expectedAudience): bool
    {
        $azp = $payload['azp'] ?? null;

        if ($azp !== null) {
            if (!is_string($azp) || !hash_equals($expectedAudience, $azp)) {
                $this->logger->warning('ID token azp does not identify this client');

                return false;
            }

            return true;
        }

        // aud が複数あるのに azp が無いと、どのクライアント向けに発行された
        // トークンなのか判別できない。
        if (is_array($audience) && count($audience) > 1) {
            $this->logger->warning('ID token has multiple audiences but no azp claim');

            return false;
        }

        return true;
    }

    /**
     * aud クレームを検証する。単一文字列と配列のどちらの表現にも対応する。
     *
     * @param mixed $aud
     */
    private function audienceMatches($aud, string $expectedAudience): bool
    {
        if (is_string($aud)) {
            return hash_equals($expectedAudience, $aud);
        }

        if (is_array($aud)) {
            foreach ($aud as $value) {
                if (is_string($value) && hash_equals($expectedAudience, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function isTimestamp($value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) || is_float($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonSegment(string $segment): ?array
    {
        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === null) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    private function base64UrlDecode(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * RSA の modulus (n) と exponent (e) から PEM 形式の公開鍵を組み立てる。
     *
     * PHP には JWK → PEM の標準関数が無いため、X.509 SubjectPublicKeyInfo の
     * DER を手で組み立てる。構造は RFC 5280 / RFC 8017 に従う:
     *
     *   SEQUENCE {
     *     SEQUENCE { OBJECT IDENTIFIER rsaEncryption, NULL }
     *     BIT STRING { SEQUENCE { INTEGER n, INTEGER e } }
     *   }
     */
    private function rsaPublicKeyToPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = $this->derSequence(
            $this->derInteger($modulus).$this->derInteger($exponent),
        );

        $algorithmIdentifier = $this->derSequence(
            "\x06".$this->derLength(strlen(self::OID_RSA_ENCRYPTION)).self::OID_RSA_ENCRYPTION."\x05\x00",
        );

        // BIT STRING の先頭 1 バイトは「未使用ビット数」。バイト境界なので常に 0。
        $bitString = "\x03".$this->derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;

        $der = $this->derSequence($algorithmIdentifier.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function derSequence(string $content): string
    {
        return "\x30".$this->derLength(strlen($content)).$content;
    }

    /**
     * DER の INTEGER を組み立てる。DER の INTEGER は符号付きなので、最上位ビットが
     * 立っている場合は正の数であることを示す 0x00 を前置する。
     */
    private function derInteger(string $raw): string
    {
        $raw = ltrim($raw, "\x00");
        if ($raw === '') {
            $raw = "\x00";
        }
        if ((ord($raw[0]) & 0x80) !== 0) {
            $raw = "\x00".$raw;
        }

        return "\x02".$this->derLength(strlen($raw)).$raw;
    }

    /**
     * DER の長さフィールドを組み立てる（127 バイト以下は短形式、それ以上は長形式）。
     */
    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
