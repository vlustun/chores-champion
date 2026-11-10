<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** 
 * New password (twice). With require_current_password it also asks for, and checks, the current one. 
 *
 * @extends AbstractType<mixed>
 */ 
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['require_current_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Current password',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new NotBlank(message: 'Enter your current password.'), new UserPassword(message: 'The current password is wrong.')],
            ]);
        }
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'first_options' => ['label' => 'New password', 'attr' => ['autocomplete' => 'new-password'], 'help' => 'Minimum 8 characters.'],
            'second_options' => ['label' => 'Repeat new password', 'attr' => ['autocomplete' => 'new-password']],
            'invalid_message' => 'The two passwords do not match.',
            'constraints' => [
                new NotBlank(message: 'Enter a new password.'),
                new Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['require_current_password' => false, 'csrf_token_id' => 'change_password']);
        $resolver->setAllowedTypes('require_current_password', 'bool');
    }
}
