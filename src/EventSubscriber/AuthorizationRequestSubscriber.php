<?php

namespace App\EventSubscriber;

use App\Entity\Application;
use App\Entity\User;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Cœur du contrôle d'accès : lors d'une demande d'autorisation (/authorize),
 * l'utilisateur (déjà connecté) n'est renvoyé vers l'application que si
 * un administrateur lui a attribué cette application. Sinon, on affiche
 * une page « Vous n'avez pas accès à cette application ».
 *
 * Les applications étant les vôtres (first-party), aucun écran de consentement n'est affiché.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final class AuthorizationRequestSubscriber
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $user = $event->getUser();
        $application = $event->getClient();

        $disabled = $application instanceof Application && !$application->isActive();
        if (!$disabled && $user instanceof User && $application instanceof Application && $user->isEnabled() && $user->hasAccessTo($application)) {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);

            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('oauth/no_access.html.twig', ['application' => $application, 'disabled' => $disabled]),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
