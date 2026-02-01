<?php

namespace Plugin\EcAuthLogin43\Controller;

use Eccube\Controller\AbstractController;
use Plugin\EcAuthLogin43\Service\PasskeyAuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EcAuthCallbackController extends AbstractController
{
    /**
     * @var PasskeyAuthService
     */
    private $passkeyAuthService;

    public function __construct(PasskeyAuthService $passkeyAuthService)
    {
        $this->passkeyAuthService = $passkeyAuthService;
    }

    /**
     * EcAuth 認証コールバック（認証不要）
     *
     * @Route("/ecauth/callback", name="ecauth_callback", methods={"GET"})
     */
    public function callback(Request $request)
    {
        // エラーレスポンスの場合
        $error = $request->query->get('error');
        if ($error !== null) {
            $this->addError('ecauth_login43.admin.callback.error', 'admin');
            log_warning('EcAuth callback error', [
                'error' => $error,
                'error_description' => $request->query->get('error_description'),
            ]);

            return $this->redirectToRoute('admin_login');
        }

        $code = $request->query->get('code');
        $state = $request->query->get('state');

        if ($code === null || $state === null) {
            $this->addError('ecauth_login43.admin.callback.error', 'admin');

            return $this->redirectToRoute('admin_login');
        }

        $session = $request->getSession();
        $redirectUri = $this->generateUrl('ecauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $Member = $this->passkeyAuthService->handleCallback($code, $state, $session, $redirectUri);

        if ($Member === null) {
            $this->addError('ecauth_login43.admin.callback.user_not_found', 'admin');

            return $this->redirectToRoute('admin_login');
        }

        return $this->redirectToRoute('admin_homepage');
    }
}
