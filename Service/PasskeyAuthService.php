<?php

namespace Plugin\EcAuthLogin43\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Member;
use Eccube\Repository\MemberRepository;
use Plugin\EcAuthLogin43\Repository\ConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class PasskeyAuthService
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var MemberRepository
     */
    private $memberRepository;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var EcAuthApiClient
     */
    private $apiClient;

    /**
     * @var UserPasswordHasherInterface
     */
    private $passwordHasher;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        MemberRepository $memberRepository,
        ConfigRepository $configRepository,
        EcAuthApiClient $apiClient,
        UserPasswordHasherInterface $passwordHasher,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->memberRepository = $memberRepository;
        $this->configRepository = $configRepository;
        $this->apiClient = $apiClient;
        $this->passwordHasher = $passwordHasher;
        $this->logger = $logger;
    }

    /**
     * Member に ecauth_subject が未設定の場合、UUID v4 を生成して保存する。
     *
     * @param Member $Member
     *
     * @return string ecauth_subject
     */
    public function ensureB2BUser(Member $Member): string
    {
        $subject = $Member->getEcauthSubject();
        if ($subject !== null && $subject !== '') {
            return $subject;
        }

        $subject = $this->generateUuidV4();
        $Member->setEcauthSubject($subject);
        $this->entityManager->flush();

        $this->logger->info('Generated ecauth_subject for Member', [
            'member_id' => $Member->getId(),
            'ecauth_subject' => $subject,
        ]);

        return $subject;
    }

    /**
     * コールバック処理: state検証 → トークン交換 → Member検索 → セッション確立
     *
     * @param string $code 認可コード
     * @param string $state stateパラメータ
     * @param SessionInterface $session
     * @param string $redirectUri コールバックURL
     *
     * @return Member|null 認証されたMember、失敗時はnull
     */
    public function handleCallback(string $code, string $state, SessionInterface $session, string $redirectUri): ?Member
    {
        // state 検証（timing-safe comparison）
        $savedState = $session->get('ecauth_state');
        $session->remove('ecauth_state');

        // PKCE の code_verifier を取り出して使い捨てる。
        // state 検証より前に取り出すのは、state 不一致で離脱する経路でも
        // セッションに verifier を残さないため。
        $savedCodeVerifier = $session->get('ecauth_code_verifier');
        $session->remove('ecauth_code_verifier');
        $codeVerifier = is_string($savedCodeVerifier) && $savedCodeVerifier !== '' ? $savedCodeVerifier : null;

        if ($savedState === null || !hash_equals($savedState, $state)) {
            $this->logger->warning('EcAuth callback state mismatch');

            return null;
        }

        // state は通ったのに verifier だけ無い場合は、認可コードに束縛された
        // code_challenge と突き合わせられずトークン交換が invalid_grant になる。
        // 原因を追えるようここで警告を残す（state 検証後に置くことで、
        // 単なる期限切れコールバックでの重複警告を避ける）。
        if ($codeVerifier === null) {
            $this->logger->warning('EcAuth callback missing code_verifier in session');
        }

        // トークン交換
        // レスポンス本文には access_token / id_token / refresh_token 等が含まれ得る。
        // 失敗時でも provider 実装によっては部分的な値が混入する可能性があるため、
        // ここでは body 全体ではなく OAuth 標準のエラーフィールド + キー一覧のみ出す。
        // 完全な redact 済みレスポンスは EcAuthApiClient::sendAndDecode 側で別途記録される。
        $tokenResult = $this->apiClient->exchangeToken($code, $redirectUri, $codeVerifier);
        if ($tokenResult['status'] !== 200) {
            $response = $tokenResult['data'] ?? null;
            $this->logger->error('EcAuth token exchange failed', [
                'status' => $tokenResult['status'],
                'redirect_uri' => $redirectUri,
                'response_error' => is_array($response) && isset($response['error']) && is_scalar($response['error'])
                    ? (string) $response['error']
                    : null,
                'response_error_description' => is_array($response) && isset($response['error_description']) && is_scalar($response['error_description'])
                    ? (string) $response['error_description']
                    : null,
                'response_keys' => is_array($response) ? array_keys($response) : null,
            ]);

            return null;
        }

        $tokenData = $tokenResult['data'];

        // ID Token から sub クレームを取得
        $idToken = $tokenData['id_token'] ?? null;
        if ($idToken === null) {
            $this->logger->error('EcAuth token response missing id_token', [
                'response_keys' => is_array($tokenData) ? array_keys($tokenData) : null,
            ]);

            return null;
        }

        $b2bSubject = $this->extractSubFromIdToken($idToken);
        if ($b2bSubject === null) {
            $this->logger->error('Failed to extract sub from id_token');

            return null;
        }

        // ecauth_subject で Member を検索
        // MemberTrait の UNIQUE 制約で通常 1 件に限定されるが、
        // 万一複数ヒットした場合（過去の reconcile バグや直接 SQL 書き込み等で
        // データが壊れている状態）は他人のセッションを張らないよう拒否する。
        $members = $this->memberRepository->findBy(['ecauth_subject' => $b2bSubject]);
        if (count($members) === 0) {
            $this->logger->warning('Member not found for ecauth_subject', [
                'ecauth_subject' => $b2bSubject,
            ]);

            return null;
        }
        if (count($members) > 1) {
            $this->logger->critical('Ambiguous ecauth_subject binding; refusing to establish session', [
                'ecauth_subject' => $b2bSubject,
                'member_count' => count($members),
                'member_ids' => array_map(static function ($m) {
                    return $m->getId();
                }, $members),
            ]);

            return null;
        }
        $Member = $members[0];

        // Access Token をセッションに保存
        $accessToken = $tokenData['access_token'] ?? null;
        if ($accessToken !== null) {
            $session->set('ecauth_access_token', $accessToken);
        }

        // 管理者セッション確立（セッション固定化攻撃対策）
        //
        // ここで TokenStorage::setToken() を呼んではいけない。
        // コールバック URL /ecauth/callback は admin firewall の pattern
        // (^/%eccube_admin_route%/) にマッチせず、customer firewall (^/) の配下で
        // 処理される。TokenStorage に Member を載せると、レスポンス時に customer
        // firewall の ContextListener::onKernelResponse() がそれを自身のセッション
        // キーへ書き出し、_security_customer に Member のトークンが入る。
        // ContextListener には firewall 個別ではなく全 user provider が渡るため、
        // 以降のフロントリクエストでも MemberProvider が refreshUser() に成功し、
        // 「ログイン済みだが ROLE_USER を持たないユーザー」としてセッションが復元される。
        // その結果 /mypage/login はログインフォームを出さずに /mypage/ へリダイレクトし、
        // /mypage/ は access_control の ROLE_USER を満たさず Access Denied になる。
        //
        // _security_admin へ直接書けば、リダイレクト先の管理画面リクエストで
        // admin firewall の ContextListener が正しくトークンを復元するため、
        // 管理画面へのログインは成立する。
        $session->migrate(true);
        $token = new UsernamePasswordToken($Member, 'admin', $Member->getRoles());
        $session->set('_security_admin', serialize($token));

        $this->logger->info('EcAuth passkey authentication successful', [
            'member_id' => $Member->getId(),
        ]);

        return $Member;
    }

    /**
     * パスワードによる本人確認を行う。
     *
     * @param Member $Member
     *
     */
    public function verifyPassword(Member $Member, string $password): bool
    {
        return $this->passwordHasher->isPasswordValid($Member, $password);
    }

    /**
     * EcAuth の register/options レスポンスに含まれる user.id (base64url の b2b_subject) を
     * Member.ecauth_subject と突き合わせ、異なっていれば EcAuth 側の値で上書きする。
     *
     * EcAuth は /v1/b2b/passkey/register/options で渡された b2b_subject が存在しない場合、
     * external_id で既存ユーザーを検索するフォールバックがある。そのため、プラグインが
     * ensureB2BUser で生成した UUID とは別の subject に解決されることがあり、その状態で
     * 登録を続行すると ID Token の sub と dtb_member.ecauth_subject が一致せず
     * パスキーログイン時に Member not found になる。
     *
     * @param array<string,mixed> $options EcAuth が返した options（user.id を含む）
     */
    public function reconcileEcauthSubjectFromOptions(Member $Member, array $options): void
    {
        $encodedUserId = $options['user']['id'] ?? null;
        if (!is_string($encodedUserId) || $encodedUserId === '') {
            return;
        }
        $resolved = $this->base64UrlDecode($encodedUserId);
        if ($resolved === null || $resolved === '') {
            return;
        }
        // EcAuth は b2b_subject を UUID v4 として発行する規約のため、想定外フォーマットが
        // 返ってきた場合は dtb_member.ecauth_subject (UNIQUE 制約あり) を壊す前に弾く。
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $resolved)) {
            $this->logger->warning('Invalid ecauth_subject format in EcAuth register/options response; skipping reconcile', [
                'member_id' => $Member->getId(),
            ]);

            return;
        }
        $current = $Member->getEcauthSubject();
        if ($current === $resolved) {
            return;
        }

        $Member->setEcauthSubject($resolved);
        $this->entityManager->flush();

        $this->logger->info('Reconciled ecauth_subject from EcAuth register/options response', [
            'member_id' => $Member->getId(),
            'previous_subject' => $current,
            'resolved_subject' => $resolved,
        ]);
    }

    /**
     * PKCE (RFC 7636) の code_verifier を生成する。
     *
     * RFC 7636 Section 4.1 は 43〜128 文字の unreserved を要求する。
     * 32 バイトの乱数を base64url した 43 文字はこの下限ちょうどで、
     * かつ 256bit のエントロピーを持つ。
     */
    public function generateCodeVerifier(): string
    {
        return $this->base64UrlEncode(random_bytes(32));
    }

    /**
     * code_verifier から code_challenge を導出する。
     *
     * RFC 7636 Section 4.2: BASE64URL-ENCODE(SHA256(ASCII(code_verifier)))
     * hash() の第3引数 (raw binary) を落とすと hex 文字列をエンコードした別物になり、
     * EcAuth 側は形式を検証しないためトークン交換まで失敗が露見しない。
     */
    public function generateCodeChallenge(string $codeVerifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
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

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Config の rp_id またはリクエストホスト名を返す。
     *
     * @param Request $request
     */
    public function getRpId(Request $request): string
    {
        $Config = $this->configRepository->get();
        $rpId = $Config ? $Config->getRpId() : null;

        if ($rpId !== null && $rpId !== '') {
            return $rpId;
        }

        return $request->getHost();
    }

    /**
     * ID Token (JWT) から sub クレームを取得する。
     * 署名検証は EcAuth 側で実施済みのため、ペイロードのデコードのみ行う。
     */
    private function extractSubFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payloadB64 = strtr($parts[1], '-_', '+/');
        $payloadB64 = str_pad($payloadB64, (int) ceil(strlen($payloadB64) / 4) * 4, '=');
        $payload = json_decode(base64_decode($payloadB64), true);
        if (!is_array($payload) || !isset($payload['sub'])) {
            return null;
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            $this->logger->warning('ID token expired');

            return null;
        }

        return $payload['sub'];
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
