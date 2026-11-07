<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:reset-password', description: 'Set a new password for a user (for when nobody can log in)')]
final class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::OPTIONAL, 'Omit to be prompted (keeps it out of shell history)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        if (null === $user) {
            $io->error('No user with that email.');

            return Command::FAILURE;
        }

        $password = $input->getArgument('password');
        if (null === $password) {
            $password = $io->askHidden('New password (min '.CreateAdminCommand::MIN_PASSWORD_LENGTH.' characters)');
        }
        $password = (string) $password;
        if (mb_strlen($password) < CreateAdminCommand::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf('Password must be at least %d characters.', CreateAdminCommand::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($this->audit->passwordEvent($user, AuditLogger::PASSWORD_CHANGED, $user));
        $this->em->flush();
        $io->success(\sprintf('Password updated for %s.', $user->getEmail()));
        if (!$user->isActive()) {
            $io->warning('This account is disabled and still cannot log in. Enable it in /admin.');
        }

        return Command::SUCCESS;
    }
}
