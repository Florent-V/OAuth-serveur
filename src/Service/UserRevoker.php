<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Révocation rapide d'un utilisateur.
 */
final class UserRevoker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessRevoker $accessRevoker,
    ) {
    }

    /**
     * Déconnecte l'utilisateur partout : sessions web, « rester connecté », appareils de confiance MFA,
     * jetons OAuth2 (access + refresh) de toutes les applications. Le compte reste actif.
     */
    public function logoutEverywhere(User $user): void
    {
        $user->invalidateAllSessions();
        $this->em->flush();
        $this->accessRevoker->revokeForUser($user);
    }

    /**
     * Bloque le compte (plus aucune connexion possible) et le déconnecte partout.
     */
    public function block(User $user): void
    {
        $user->setEnabled(false);
        $this->logoutEverywhere($user);
    }
}
