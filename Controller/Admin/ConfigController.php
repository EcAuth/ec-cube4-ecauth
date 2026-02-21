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
        $form = $this->createForm(ConfigType::class, $Config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $Config = $form->getData();
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
        ];
    }
}
