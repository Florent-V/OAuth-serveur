<?php

namespace App\Command;

use App\Entity\Application;
use App\Repository\UserRepository;
use App\Service\AccessRevoker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:access:grant', description: 'Donne (ou retire avec --revoke) l\'accès d\'un utilisateur à une application')]
final class GrantAccessCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AccessRevoker $revoker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail de l\'utilisateur')
            ->addArgument('client_id', InputArgument::REQUIRED, 'Client ID de l\'application')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Retire l\'accès au lieu de le donner');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        $application = $this->em->find(Application::class, (string) $input->getArgument('client_id'));

        if (null === $user || null === $application) {
            $io->error(null === $user ? 'Utilisateur introuvable.' : 'Application introuvable.');

            return Command::FAILURE;
        }

        if ($input->getOption('revoke')) {
            $user->removeApplication($application);
            $this->em->flush();
            $this->revoker->revokeForUser($user, $application);
            $io->success(\sprintf('Accès de %s à « %s » retiré.', $user->getEmail(), $application->getName()));
        } else {
            $user->addApplication($application);
            $this->em->flush();
            $io->success(\sprintf('%s a maintenant accès à « %s ».', $user->getEmail(), $application->getName()));
        }

        return Command::SUCCESS;
    }
}
