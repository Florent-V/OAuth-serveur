<?php

namespace App\EventSubscriber;

use App\Entity\Application;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Depuis league/oauth2-server-bundle 1.2.3, un client inactif est invisible pour le serveur OAuth2 :
 * /authorize répondrait par un JSON « invalid_client ». On intercepte la demande (après le pare-feu,
 * donc l'utilisateur est déjà connecté) pour garder la page explicite « application désactivée ».
 */
final class InactiveApplicationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'oauth2_authorize' !== $request->attributes->get('_route')) {
            return;
        }

        $clientId = $request->query->get('client_id');
        $application = \is_string($clientId) ? $this->em->find(Application::class, $clientId) : null;
        if (!$application instanceof Application || $application->isActive()) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('oauth/no_access.html.twig', ['application' => $application, 'disabled' => true]),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
