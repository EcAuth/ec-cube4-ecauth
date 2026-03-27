<?php

namespace Plugin\EcAuthLogin43\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\EcAuthLogin43\Service\EcAuthApiClient;
use Plugin\EcAuthLogin43\Service\PasskeyAuthService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class PasskeyController extends AbstractController
{
    /**
     * @var EcAuthApiClient
     */
    private $apiClient;

    /**
     * @var PasskeyAuthService
     */
    private $passkeyAuthService;

    /**
     * @var string
     */
    private $authJsVersion;

    public function __construct(
        EcAuthApiClient $apiClient,
        PasskeyAuthService $passkeyAuthService,
        string $ecauth_auth_js_version,
    ) {
        $this->apiClient = $apiClient;
        $this->passkeyAuthService = $passkeyAuthService;
        $this->authJsVersion = $ecauth_auth_js_version;
    }

    /**
     * パスキー管理画面（一覧）
     *
     * @Route("/%eccube_admin_route%/ecauth/passkey/", name="ecauth_login43_admin_passkey")
     * @Template("@EcAuthLogin43/admin/passkey_list.twig")
     */
    public function index(Request $request)
    {
        $session = $request->getSession();
        $accessToken = $session->get('ecauth_access_token');

        $passkeys = [];
        $error = null;

        if ($accessToken) {
            $result = $this->apiClient->listPasskeys($accessToken);
            if ($result['status'] === 200) {
                $passkeys = $result['data']['credentials'] ?? [];
            } else {
                $error = 'ecauth_login43.admin.passkey.config_required';
            }
        }

        return [
            'passkeys' => $passkeys,
            'error' => $error,
            'ecauth_auth_js_version' => $this->authJsVersion,
        ];
    }

    /**
     * パスキー削除
     *
     * @Route("/%eccube_admin_route%/ecauth/passkey/{credentialId}/delete", name="ecauth_login43_admin_passkey_delete", methods={"DELETE"})
     */
    public function delete(Request $request, string $credentialId)
    {
        $this->isTokenValid();

        $session = $request->getSession();
        $accessToken = $session->get('ecauth_access_token');

        if (!$accessToken) {
            $this->addError('ecauth_login43.admin.passkey.config_required', 'admin');

            return $this->redirectToRoute('ecauth_login43_admin_passkey');
        }

        $result = $this->apiClient->deletePasskey($accessToken, $credentialId);

        if ($result['status'] === 200 || $result['status'] === 204) {
            $this->addSuccess('ecauth_login43.admin.passkey.delete.success', 'admin');
        } else {
            $this->addError('ecauth_login43.admin.passkey.delete.error', 'admin');
        }

        return $this->redirectToRoute('ecauth_login43_admin_passkey');
    }

    /**
     * 本人確認（パスワード再入力）
     *
     * @Route("/%eccube_admin_route%/ecauth/passkey/verify-password", name="ecauth_login43_admin_passkey_verify_password", methods={"POST"})
     */
    public function verifyPassword(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ecauth_passkey', $request->headers->get('X-CSRF-TOKEN', ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid request body'], 400);
        }
        $password = $data['password'] ?? '';

        if ($password === '') {
            return $this->json(['error' => 'Password is required'], 400);
        }

        /** @var \Eccube\Entity\Member $Member */
        $Member = $this->getUser();

        if (!$this->passkeyAuthService->verifyPassword($Member, $password)) {
            return $this->json(['error' => 'ecauth_login43.admin.passkey.verify_password.error'], 401);
        }

        // ecauth_subject を確保（JIT プロビジョニング）
        $b2bSubject = $this->passkeyAuthService->ensureB2BUser($Member);

        return $this->json([
            'b2b_subject' => $b2bSubject,
        ]);
    }
}
