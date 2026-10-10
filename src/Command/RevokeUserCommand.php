<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\UserRevoker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:revoke', description: 'Bloque un utilisateur et le déconnecte de partout (ou seulement le déconnecte avec --logout-only)')]
final class RevokeUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserRevoker $revoker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail de l\'utilisateur')
            ->addOption('logout-only', null, InputOption::VALUE_NONE, 'Déconnecte partout sans bloquer le compte');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        if (null === $user) {
            $io->error('Utilisateur introuvable.');

            return Command::FAILURE;
        }

        if ($input->getOption('logout-only')) {
            $this->revoker->logoutEverywhere($user);
            $io->success(\sprintf('%s est déconnecté de partout (sessions, appareils de confiance, jetons).', $user->getEmail()));
        } else {
            $this->revoker->block($user);
            $io->success(\sprintf('%s est bloqué et déconnecté de toutes les applications.', $user->getEmail()));
        }

        return Command::SUCCESS;
    }
}
