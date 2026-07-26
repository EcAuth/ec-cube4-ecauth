<?php

namespace Plugin\EcAuthLogin43\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\EcAuthLogin43\Form\Type\Admin\ConfigType;
use Plugin\EcAuthLogin43\Repository\ConfigRepository;
use Plugin\EcAuthLogin43\Service\ClientResolveService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var ClientResolveService
     */
    protected $clientResolveService;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * 申込フォーム (ec-auth.io) の URL。services.yaml の
     * ecauth_default_signup_url / 環境変数 ECAUTH_SIGNUP_URL で決まる。
     *
     * @var string
     */
    protected $signupUrl;

    /**
     * マイページ (ec-auth.io) の URL。services.yaml の
     * ecauth_default_mypage_url / 環境変数 ECAUTH_MYPAGE_URL で決まる。
     *
     * @var string
     */
    protected $mypageUrl;

    public function __construct(
        ConfigRepository $configRepository,
        ClientResolveService $clientResolveService,
        TranslatorInterface $translator,
        string $signupUrl,
        string $mypageUrl
    ) {
        $this->configRepository = $configRepository;
        $this->clientResolveService = $clientResolveService;
        $this->translator = $translator;
        $this->signupUrl = $signupUrl;
        $this->mypageUrl = $mypageUrl;
    }

    /**
     * EC-CUBE 管理画面のプラグイン一覧 (/admin/store/plugin) で歯車アイコンを
     * 表示させるため、Container::underscore(Plugin.code) + '_admin_config' という
     * ルート名規約 (ec_auth_login43_admin_config) でも引けるよう別名を追加する。
     * 既存箇所は ecauth_login43_admin_config を使い続けるため両方残す。
     *
     * @Route("/%eccube_admin_route%/ecauth_login43/config", name="ecauth_login43_admin_config")
     * @Route("/%eccube_admin_route%/ecauth_login43/config", name="ec_auth_login43_admin_config")
     * @Template("@EcAuthLogin43/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $hasClientSecret = $Config && $Config->getClientSecret() !== null && $Config->getClientSecret() !== '';

        $form = $this->createForm(ConfigType::class, $Config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $Config = $form->getData();

            $inputUrl = trim((string) $Config->getEcauthBaseUrl());
            if ($inputUrl === '') {
                $resolved = $this->clientResolveService->resolve((string) $Config->getClientId());
                if (!$resolved['success']) {
                    $form->get('client_id')->addError(
                        new FormError($this->translator->trans('ecauth_login43.admin.config.client_resolve.failed')),
                    );

                    return [
                        'form' => $form->createView(),
                        'has_client_secret' => $hasClientSecret,
                        'signup_url' => $this->signupUrl,
                        'mypage_url' => $this->mypageUrl,
                    ];
                }
                $Config->setEcauthBaseUrl($resolved['base_url']);
            } else {
                $Config->setEcauthBaseUrl(rtrim($inputUrl, '/'));
            }

            $clientSecret = $form->get('client_secret')->getData();
            if ($clientSecret !== null && $clientSecret !== '') {
                $Config->setClientSecret($clientSecret);
            }
            $this->entityManager->persist($Config);
            $this->entityManager->flush();

            $this->addSuccess('ecauth_login43.admin.config.save.success', 'admin');

            return $this->redirectToRoute('ecauth_login43_admin_config');
        }

        return [
            'form' => $form->createView(),
            'has_client_secret' => $hasClientSecret,
            'signup_url' => $this->signupUrl,
            'mypage_url' => $this->mypageUrl,
        ];
    }
}
