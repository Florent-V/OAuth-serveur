<?php

namespace App\EventSubscriber;

use App\Repository\ApplicationRepository;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Permet aux applications de rediriger vers /logout?redirect_uri=https://app1.mydomain.com/
 * pour une déconnexion globale (SSO). Pour éviter les « open redirects », l'URL n'est
 * acceptée que si son origine (schéma + hôte + port) correspond à celle de l'URL d'accueil
 * ou d'une redirect URI d'une application active.
 */
#[AsEventListener(priority: -10)]
final class LogoutSubscriber
{
    public function __construct(private readonly ApplicationRepository $applications)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $redirect = $event->getRequest()->query->getString('redirect_uri');
        if ('' === $redirect || null === ($origin = self::origin($redirect))) {
            return;
        }

        foreach ($this->applications->findActive() as $application) {
            $allowed = array_map(static fn (RedirectUri $uri): string => (string) $uri, $application->getRedirectUris());
            if (null !== $application->getHomeUrl()) {
                $allowed[] = $application->getHomeUrl();
            }

            foreach ($allowed as $url) {
                if (self::origin($url) === $origin) {
                    $event->setResponse(new RedirectResponse($redirect));

                    return;
                }
            }
        }
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
