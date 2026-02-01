<?php

namespace Plugin\EcAuthLogin43\Form\Type\Admin;

use Plugin\EcAuthLogin43\Entity\Config;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('ecauth_base_url', UrlType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('client_id', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('client_secret', PasswordType::class, [
                'always_empty' => false,
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('rp_id', TextType::class, [
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Config::class,
        ]);
    }
}
