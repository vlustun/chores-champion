<?php

declare(strict_types=1);

namespace App\Service\PasswordReset;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/** Creates a reset token for a user and emails the link. Shared by the public form and the admin action. */
class PasswordResetService
{
    public function __construct(
        private readonly ResetPasswordHelperInterface $helper,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
        #[Autowire(env: 'MAILER_DSN')]
        private readonly string $dsn,
    ) {
    }

    /** True when mail is discarded (null transport): callers may show the link instead. */
    public function isMailDisabled(): bool
    {
        return str_starts_with($this->dsn, 'null:');
    }

    /**
     * @return string the absolute reset URL (also emailed)
     *
     * @throws ResetPasswordExceptionInterface when throttled or the token cannot be created
     */
    public function send(User $user): string
    {
        $token = $this->helper->generateResetToken($user);
        $url = $this->urls->generate('app_reset_password', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address($this->from, 'Chores Champion'))
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject('Reset your Chores Champion password')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context(['url' => $url, 'user' => $user, 'lifetimeMinutes' => intdiv($this->helper->getTokenLifetime(), 60)]);
        $this->mailer->send($email);

        return $url;
    }
}
