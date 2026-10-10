<?php

namespace App\Command;

use App\Entity\Application;
use App\Repository\UserRepository;
use App\Security\BackchannelLogout\BackchannelLogoutNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:application:test-backchannel-logout', description: 'Envoie un logout token à une application (teste son URL de back-channel logout)')]
final class TestBackchannelLogoutCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly BackchannelLogoutNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client_id', InputArgument::REQUIRED, 'Client ID de l\'application')
            ->addArgument('email', InputArgument::REQUIRED, 'Utilisateur à déconnecter de cette application')
            ->setHelp('L\'utilisateur est réellement déconnecté de l\'application (ses jetons ne sont pas révoqués).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $application = $this->em->find(Application::class, (string) $input->getArgument('client_id'));
        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        if (null === $application || null === $user) {
            $io->error(null === $application ? 'Application introuvable.' : 'Utilisateur introuvable.');

            return Command::FAILURE;
        }
        if (null === $application->getBackchannelLogoutUri()) {
            $io->error('Cette application n\'a pas d\'URL de back-channel logout.');

            return Command::FAILURE;
        }

        $this->notifier->notify($user, [$application]);
        $result = current($this->notifier->flush());

        if (\is_int($result)) {
            $io->success(\sprintf('%s a répondu HTTP %d : notification acceptée.', $application->getBackchannelLogoutUri(), $result));

            return Command::SUCCESS;
        }
        $io->error(\sprintf('Échec : %s', $result));

        return Command::FAILURE;
    }
}
