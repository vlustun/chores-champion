<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<mixed> */
final class ResetPasswordRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'attr' => ['autocomplete' => 'email', 'autofocus' => true],
            'constraints' => [new NotBlank(message: 'Enter your email.'), new Email()],
        ]);
    }
}
