<?php

namespace Plugin\EcAuthLogin43\Controller;

use Eccube\Controller\AbstractController;
use Plugin\EcAuthLogin43\Service\EcAuthApiClient;
use Plugin\EcAuthLogin43\Service\PasskeyAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasskeyAuthController extends AbstractController
{
    /**
     * @var EcAuthApiClient
     */
    private $apiClient;

    /**
     * @var PasskeyAuthService
     */
    private $passkeyAuthService;

    public function __construct(
        EcAuthApiClient $apiClient,
        PasskeyAuthService $passkeyAuthService
    ) {
        $this->apiClient = $apiClient;
        $this->passkeyAuthService = $passkeyAuthService;
    }

    /**
     * パスキー認証オプション取得（認証不要）
     *
     * @Route("/ecauth/passkey/authenticate/options", name="ecauth_passkey_authenticate_options", methods={"POST"})
     */
    public function authenticateOptions(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ecauth_passkey', $request->headers->get('X-CSRF-TOKEN', ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $rpId = $this->passkeyAuthService->getRpId($request);
        $result = $this->apiClient->authenticateOptions($rpId);

        if ($result['status'] !== 200) {
            return $this->json(['error' => 'Failed to get authentication options'], $result['status']);
        }

        // session_id をサーバーサイドセッションに保存
        $session = $request->getSession();
        $session->set('ecauth_passkey_session_id', $result['data']['session_id'] ?? null);

        return $this->json($result['data']);
    }

    /**
     * パスキー認証検証（認証不要）
     *
     * @Route("/ecauth/passkey/authenticate/verify", name="ecauth_passkey_authenticate_verify", methods={"POST"})
     */
    public function authenticateVerify(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ecauth_passkey', $request->headers->get('X-CSRF-TOKEN', ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['response'])) {
            return $this->json(['error' => 'Invalid request body'], 400);
        }

        $session = $request->getSession();
        $sessionId = $session->get('ecauth_passkey_session_id');
        $session->remove('ecauth_passkey_session_id');

        if ($sessionId === null) {
            return $this->json(['error' => 'Session expired'], 400);
        }

        // state 生成・セッション保存
        $state = bin2hex(random_bytes(32));
        $session->set('ecauth_state', $state);

        $redirectUri = $this->generateUrl('ecauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $result = $this->apiClient->authenticateVerify(
            $sessionId,
            $redirectUri,
            $state,
            $data['response']
        );

        if ($result['status'] !== 200) {
            $session->remove('ecauth_state');

            return $this->json(['error' => 'Authentication failed'], $result['status']);
        }

        return $this->json($result['data']);
    }

    /**
     * パスキー登録オプション取得（管理者ログイン必須）
     *
     * @Route("/%eccube_admin_route%/ecauth/passkey/register/options", name="ecauth_passkey_register_options", methods={"POST"})
     */
    public function registerOptions(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ecauth_passkey', $request->headers->get('X-CSRF-TOKEN', ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $b2bSubject = $data['b2b_subject'] ?? null;
        if ($b2bSubject === null) {
            return $this->json(['error' => 'b2b_subject is required'], 400);
        }

        $rpId = $this->passkeyAuthService->getRpId($request);
        $displayName = $data['display_name'] ?? null;
        $deviceName = $data['device_name'] ?? null;

        $result = $this->apiClient->registerOptions($rpId, $b2bSubject, $displayName, $deviceName);

        if ($result['status'] !== 200) {
            return $this->json(['error' => 'Failed to get registration options'], $result['status']);
        }

        // session_id をサーバーサイドセッションに保存
        $session = $request->getSession();
        $session->set('ecauth_register_session_id', $result['data']['session_id'] ?? null);

        return $this->json($result['data']);
    }

    /**
     * パスキー登録検証（管理者ログイン必須）
     *
     * @Route("/%eccube_admin_route%/ecauth/passkey/register/verify", name="ecauth_passkey_register_verify", methods={"POST"})
     */
    public function registerVerify(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ecauth_passkey', $request->headers->get('X-CSRF-TOKEN', ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['response'])) {
            return $this->json(['error' => 'Invalid request body'], 400);
        }

        $session = $request->getSession();
        $sessionId = $session->get('ecauth_register_session_id');
        $session->remove('ecauth_register_session_id');

        if ($sessionId === null) {
            return $this->json(['error' => 'Session expired'], 400);
        }

        $deviceName = $data['device_name'] ?? null;

        $result = $this->apiClient->registerVerify($sessionId, $data['response'], $deviceName);

        if ($result['status'] !== 200) {
            return $this->json(['error' => 'Registration failed'], $result['status']);
        }

        return $this->json($result['data']);
    }
}
