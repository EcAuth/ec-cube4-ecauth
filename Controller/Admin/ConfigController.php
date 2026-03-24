<?php

namespace Plugin\EcAuthLogin43\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\EcAuthLogin43\Form\Type\Admin\ConfigType;
use Plugin\EcAuthLogin43\Repository\ConfigRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ConfigController extends AbstractController
{
    private const BASE_DOMAIN = '.ec-auth.io';

    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    public function __construct(ConfigRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }

    /**
     * @Route("/%eccube_admin_route%/ecauth_login43/config", name="ecauth_login43_admin_config")
     * @Template("@EcAuthLogin43/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $hasClientSecret = $Config && $Config->getClientSecret() !== null && $Config->getClientSecret() !== '';

        // DB のフル URL からサブドメイン部分を抽出してフォームにセット
        $subdomain = $this->extractSubdomain($Config ? $Config->getEcauthBaseUrl() : null);
        if ($subdomain !== null) {
            $Config->setEcauthBaseUrl($subdomain);
        }

        $form = $this->createForm(ConfigType::class, $Config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $Config = $form->getData();

            // サブドメインをフル URL に変換して保存
            $inputSubdomain = $Config->getEcauthBaseUrl();
            $Config->setEcauthBaseUrl('https://' . $inputSubdomain . self::BASE_DOMAIN);

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
        ];
    }

    private function extractSubdomain(?string $baseUrl): ?string
    {
        if ($baseUrl === null || $baseUrl === '') {
            return null;
        }

        // https://{subdomain}.ec-auth.io からサブドメインを抽出
        $pattern = '#^https?://(.+)' . preg_quote(self::BASE_DOMAIN, '#') . '/?$#';
        if (preg_match($pattern, $baseUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
