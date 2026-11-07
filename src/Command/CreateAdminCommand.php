<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create an admin user (or promote an existing one with --promote)')]
final class CreateAdminCommand extends Command
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED, 'Min '.self::MIN_PASSWORD_LENGTH.' characters')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'IANA timezone', 'UTC')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Phone number')
            ->addOption('promote', null, InputOption::VALUE_NONE, 'Promote an existing user instead of failing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf('Password must be at least %d characters.', self::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        $user = $this->users->findOneByEmail($email);
        if (null !== $user) {
            if (!$input->getOption('promote')) {
                $io->error('A user with this email already exists. Use --promote to make them admin.');

                return Command::FAILURE;
            }
        } else {
            $user = new User();
            $user->setEmail($email)
                ->setName((string) $input->getArgument('name'))
                ->setPhoneNr($input->getOption('phone'))
                ->setTimezone((string) $input->getOption('timezone'))
                ->setTimezoneManual(true);
        }
        $user->setRoles(['ROLE_ADMIN'])->setIsActive(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $v) {
                $io->error($v->getPropertyPath().': '.$v->getMessage());
            }

            return Command::INVALID;
        }

        $this->em->persist($user);
        $this->em->flush();
        $io->success(\sprintf('Admin %s ready.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
