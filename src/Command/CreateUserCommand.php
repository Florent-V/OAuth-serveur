<?php

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

#[AsCommand(name: 'app:user:create', description: 'Crée un utilisateur (utile pour créer le premier administrateur)')]
final class CreateUserCommand extends Command
{
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
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Pseudo unique (déduit de l\'e-mail si absent)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Donne le rôle administrateur')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe (demandé interactivement si absent)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if (null !== $this->users->findOneByEmail($email)) {
            $io->error('Un utilisateur existe déjà avec cette adresse e-mail.');

            return Command::FAILURE;
        }

        $password = $input->getOption('password') ?? $io->askHidden('Mot de passe');
        if (!\is_string($password) || '' === $password) {
            $io->error('Mot de passe requis.');

            return Command::FAILURE;
        }

        $user = (new User())
            ->setEmail($email)
            ->setUsername((string) ($input->getOption('username') ?? $this->suggestUsername($email)))
            ->setRoles($input->getOption('admin') ? ['ROLE_ADMIN'] : []);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $errors = $this->validator->validate($user);
        if (\count($errors) > 0) {
            $io->error((string) $errors);

            return Command::FAILURE;
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('Utilisateur %s créé%s.', $user->getEmail(), $user->isAdmin() ? ' (administrateur)' : ''));

        return Command::SUCCESS;
    }

    /**
     * Pseudo libre déduit de la partie locale de l'e-mail (« jean.dupont », puis « jean.dupont2 »...).
     */
    private function suggestUsername(string $email): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', (string) strstr($email, '@', true)) ?? '';
        $base = substr(str_pad($base, 3, '_'), 0, User::USERNAME_MAX_LENGTH - 4);

        $username = $base;
        for ($i = 2; null !== $this->users->findOneByUsername($username); ++$i) {
            $username = $base.$i;
        }

        return $username;
    }
}
