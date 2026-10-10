<?php

namespace App\Command;

use App\Entity\Application;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

#[AsCommand(name: 'app:application:create', description: 'Déclare une application (client OAuth2)')]
final class CreateApplicationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private readonly PasswordHasherInterface $secretHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Nom de l\'application')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Client ID (aléatoire par défaut)')
            ->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redirect URI (répétable)')
            ->addOption('home-url', null, InputOption::VALUE_REQUIRED, 'URL de l\'application')
            ->addOption('backchannel-logout-uri', null, InputOption::VALUE_REQUIRED, 'URL de back-channel logout de l\'application')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Client public (sans secret, PKCE)')
            ->addOption('open-registration', null, InputOption::VALUE_NONE, 'Accès automatique pour les utilisateurs qui s\'inscrivent depuis cette application')
            ->addOption('skip-if-exists', null, InputOption::VALUE_NONE, 'Ne fait rien (sans erreur) si le client ID existe déjà');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = (string) ($input->getOption('id') ?? bin2hex(random_bytes(16)));
        if (null !== $this->em->find(Application::class, $identifier)) {
            if ($input->getOption('skip-if-exists')) {
                $io->note(\sprintf('L\'application « %s » existe déjà.', $identifier));

                return Command::SUCCESS;
            }
            $io->error(\sprintf('Le client ID « %s » existe déjà.', $identifier));

            return Command::FAILURE;
        }

        $backchannel = $input->getOption('backchannel-logout-uri');
        if (null !== $backchannel && !\in_array(parse_url($backchannel, \PHP_URL_SCHEME), ['http', 'https'], true)) {
            $io->error('URL de back-channel logout invalide (http:// ou https:// attendu).');

            return Command::FAILURE;
        }

        $secret = $input->getOption('public') ? null : bin2hex(random_bytes(32));

        $application = (new Application((string) $input->getArgument('name'), $identifier, null === $secret ? null : $this->secretHasher->hash($secret)))
            ->setHomeUrl($input->getOption('home-url'))
            ->setBackchannelLogoutUri($input->getOption('backchannel-logout-uri'))
            ->setOpenRegistration((bool) $input->getOption('open-registration'))
            ->setRedirectUris(...array_map(static fn (string $uri) => new RedirectUri($uri), $input->getOption('redirect-uri')))
            ->setGrants(new Grant(OAuth2Grants::AUTHORIZATION_CODE), new Grant(OAuth2Grants::REFRESH_TOKEN));

        $this->em->persist($application);
        $this->em->flush();

        $io->success('Application créée.');
        $io->definitionList(
            ['Client ID' => $identifier],
            ['Client secret' => $secret ?? '(client public, pas de secret)'],
        );
        if (null !== $secret) {
            $io->warning('Notez le client secret maintenant : il est stocké haché et ne pourra plus être affiché.');
        }

        return Command::SUCCESS;
    }
}
