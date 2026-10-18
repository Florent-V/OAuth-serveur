<?php

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ajoute l'e-mail et le pseudo de l'utilisateur dans le JWT d'accès, pour que les applications
 * puissent identifier l'utilisateur sans appeler /api/userinfo (en vérifiant la signature
 * avec la clé publique du serveur).
 */
#[AsEventListener(event: OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE)]
final class AccessTokenClaimsSubscriber
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function __invoke(AccessTokenExtraClaimsResolveEvent $event): void
    {
        $identifier = $event->getUserIdentifier();
        if (null === $identifier || '' === $identifier) {
            return;
        }

        $user = $this->users->findOneByEmail($identifier);
        if (null === $user) {
            return;
        }

        $event->setExtraClaims([
            'uid' => $user->getUuid()->toRfc4122(),
            'email' => $user->getEmail(),
            'preferred_username' => $user->getUsername(),
            // Doublon de preferred_username, conservé pour les applications qui lisent « name ».
            'name' => $user->getUsername(),
        ]);
    }
}
