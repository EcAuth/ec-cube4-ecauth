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

    public function __construct(
        ConfigRepository $configRepository,
        ClientResolveService $clientResolveService,
        TranslatorInterface $translator
    ) {
        $this->configRepository = $configRepository;
        $this->clientResolveService = $clientResolveService;
        $this->translator = $translator;
    }

    /**
     * @Route("/%eccube_admin_route%/ecauth_login43/config", name="ecauth_login43_admin_config")
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
                        new FormError($this->translator->trans('ecauth_login43.admin.config.client_resolve.failed'))
                    );

                    return [
                        'form' => $form->createView(),
                        'has_client_secret' => $hasClientSecret,
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
        ];
    }
}
