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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
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
     * @var TokenStorageInterface
     */
    private $tokenStorage;

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
        TokenStorageInterface $tokenStorage,
        UserPasswordHasherInterface $passwordHasher,
        LoggerInterface $logger,
    ) {
        $this->entityManager = $entityManager;
        $this->memberRepository = $memberRepository;
        $this->configRepository = $configRepository;
        $this->apiClient = $apiClient;
        $this->tokenStorage = $tokenStorage;
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

        if ($savedState === null || !hash_equals($savedState, $state)) {
            $this->logger->warning('EcAuth callback state mismatch');

            return null;
        }

        // トークン交換
        $tokenResult = $this->apiClient->exchangeToken($code, $redirectUri);
        if ($tokenResult['status'] !== 200) {
            $this->logger->error('EcAuth token exchange failed', [
                'status' => $tokenResult['status'],
            ]);

            return null;
        }

        $tokenData = $tokenResult['data'];

        // ID Token から sub クレームを取得
        $idToken = $tokenData['id_token'] ?? null;
        if ($idToken === null) {
            $this->logger->error('EcAuth token response missing id_token');

            return null;
        }

        $b2bSubject = $this->extractSubFromIdToken($idToken);
        if ($b2bSubject === null) {
            $this->logger->error('Failed to extract sub from id_token');

            return null;
        }

        // ecauth_subject で Member を検索
        $Member = $this->memberRepository->findOneBy(['ecauth_subject' => $b2bSubject]);
        if ($Member === null) {
            $this->logger->warning('Member not found for ecauth_subject', [
                'ecauth_subject' => $b2bSubject,
            ]);

            return null;
        }

        // Access Token をセッションに保存
        $accessToken = $tokenData['access_token'] ?? null;
        if ($accessToken !== null) {
            $session->set('ecauth_access_token', $accessToken);
        }

        // 管理者セッション確立（セッション固定化攻撃対策）
        $session->migrate(true);
        $token = new UsernamePasswordToken($Member, 'admin', $Member->getRoles());
        $this->tokenStorage->setToken($token);
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

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || !isset($payload['sub'])) {
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
