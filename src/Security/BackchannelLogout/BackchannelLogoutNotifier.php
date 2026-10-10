<?php

namespace App\Security\BackchannelLogout;

use App\Entity\Application;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * OpenID Connect Back-Channel Logout : prévient les applications, de serveur à serveur, qu'un utilisateur
 * doit être déconnecté (blocage, « déconnecter partout », accès retiré, compte supprimé, mot de passe réinitialisé).
 *
 * Les notifications sont mises en file pendant la requête puis envoyées après la réponse
 * (kernel.terminate / console.terminate), en parallèle, avec délai d'attente et nouvelles tentatives :
 * une application lente ou en panne ne bloque jamais l'administration. En cas d'échec définitif,
 * l'application coupe tout de même l'accès au prochain renouvellement de jeton (refusé).
 */
final class BackchannelLogoutNotifier implements EventSubscriberInterface, ResetInterface
{
    /** @var array<string, array{client: string, uri: string, sub: string, uid: int}> */
    private array $pending = [];

    public function __construct(
        private readonly LogoutTokenFactory $tokens,
        private readonly HttpClientInterface $backchannelLogoutClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<Application> $applications applications à prévenir (celles sans URL de back-channel sont ignorées)
     */
    public function notify(User $user, iterable $applications): void
    {
        if (null === $user->getId()) {
            return;
        }
        foreach ($applications as $application) {
            $uri = $application->getBackchannelLogoutUri();
            if (null === $uri) {
                continue;
            }
            // Les données sont copiées tout de suite : l'utilisateur peut être supprimé avant l'envoi
            $this->pending[$application->getIdentifier().'|'.$user->getId()] = [
                'client' => $application->getIdentifier(),
                'uri' => $uri,
                'sub' => $user->getUserIdentifier(),
                'uid' => $user->getId(),
            ];
        }
    }

    /**
     * Envoie les notifications en attente.
     *
     * @return array<string, int|string> code HTTP (ou message d'erreur) par client ID et utilisateur
     */
    public function flush(): array
    {
        $pending = $this->pending;
        $this->pending = [];
        $results = [];
        $responses = [];

        foreach ($pending as $key => $notification) {
            try {
                $responses[$key] = $this->backchannelLogoutClient->request('POST', $notification['uri'], [
                    'body' => ['logout_token' => $this->tokens->create($notification['client'], $notification['sub'], $notification['uid'])],
                    'headers' => ['Accept' => 'application/json'],
                ]);
            } catch (\Throwable $e) {
                $results[$key] = $this->failure($notification, $e->getMessage());
            }
        }

        // Les requêtes sont envoyées en parallèle ; on attend ici les réponses
        foreach ($responses as $key => $response) {
            $notification = $pending[$key];
            try {
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $this->logger->info('Back-channel logout envoyé à {client} pour l\'utilisateur {uid}.', $notification);
                    $results[$key] = $status;
                } else {
                    $results[$key] = $this->failure($notification, 'HTTP '.$status);
                }
            } catch (ExceptionInterface $e) {
                $results[$key] = $this->failure($notification, $e->getMessage());
            }
        }

        return $results;
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
        ];
    }

    /**
     * @param array{client: string, uri: string, sub: string, uid: int} $notification
     */
    private function failure(array $notification, string $error): string
    {
        $this->logger->warning('Échec du back-channel logout vers {client} ({uri}) pour l\'utilisateur {uid} : {error}. '
            .'L\'accès sera coupé au prochain renouvellement de jeton.', $notification + ['error' => $error]);

        return $error;
    }
}
