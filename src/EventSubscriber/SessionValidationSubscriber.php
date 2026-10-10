<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Révocation immédiate des sessions web : à chaque requête, on vérifie que le compte est toujours
 * actif et que sa « version de session » n'a pas changé depuis la connexion (elle est incrémentée
 * par l'admin via « Déconnecter partout » / « Bloquer »). Sinon l'utilisateur est déconnecté.
 */
final class SessionValidationSubscriber
{
    private const SESSION_KEY = '_app_session_version';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $request = $event->getRequest();
        if ('main' === $event->getFirewallName() && $user instanceof User && $request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $user->getSessionVersion());
        }
    }

    // Après le pare-feu (priorité 8), qui recharge l'utilisateur depuis la base
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || 'main' !== $this->security->getFirewallConfig($event->getRequest())?->getName()) {
            return;
        }

        $token = $this->security->getToken();
        $user = $token?->getUser();
        $request = $event->getRequest();
        if (!$user instanceof User || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $version = $session->get(self::SESSION_KEY);
        if (null === $version && !$token instanceof TwoFactorTokenInterface) {
            $session->set(self::SESSION_KEY, $user->getSessionVersion());

            return;
        }

        if ($user->isEnabled() && (null === $version || $version === $user->getSessionVersion())) {
            return;
        }

        $this->security->logout(false);
        $request->getSession()->getFlashBag()->add('warning', $user->isEnabled()
            ? 'Votre session a été fermée. Veuillez vous reconnecter.'
            : 'Votre compte est désactivé. Contactez l\'administrateur.');
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }
}
