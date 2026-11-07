<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Service\Time\TimezoneResolver;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('phoneNr', TelType::class, ['required' => false, 'label' => 'Phone'])
            ->add('timezone', ChoiceType::class, ['label' => 'Timezone', 'choices' => array_combine(TimezoneResolver::all(), TimezoneResolver::all()), 'choice_translation_domain' => false])
            ->add('timezoneAuto', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Detect my timezone automatically from this device',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
