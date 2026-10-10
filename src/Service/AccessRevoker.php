<?php

namespace App\Service;

use App\Entity\Application;
use App\Entity\User;
use App\Security\BackchannelLogout\BackchannelLogoutNotifier;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;

/**
 * Révoque les jetons OAuth2 d'un utilisateur, pour une application ou pour toutes.
 *
 * Utilisé quand un administrateur retire l'accès à une application, désactive
 * ou supprime un compte : les applications ne pourront plus rafraîchir leurs jetons, et celles qui
 * ont déclaré une URL de back-channel logout sont prévenues immédiatement.
 */
final class AccessRevoker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BackchannelLogoutNotifier $backchannelLogout,
    ) {
    }

    public function revoke(string $userIdentifier, ?Application $application = null): void
    {
        $accessTokens = $this->em->createQueryBuilder()
            ->select('a.identifier')
            ->from(AccessToken::class, 'a')
            ->where('a.userIdentifier = :user')
            ->setParameter('user', $userIdentifier);

        $update = $this->em->createQueryBuilder()
            ->update(AccessToken::class, 'a')
            ->set('a.revoked', ':revoked')
            ->where('a.userIdentifier = :user')
            ->setParameter('revoked', true)
            ->setParameter('user', $userIdentifier);

        $codes = $this->em->createQueryBuilder()
            ->update(AuthorizationCode::class, 'c')
            ->set('c.revoked', ':revoked')
            ->where('c.userIdentifier = :user')
            ->setParameter('revoked', true)
            ->setParameter('user', $userIdentifier);

        if (null !== $application) {
            $accessTokens->andWhere('a.client = :client')->setParameter('client', $application->getIdentifier());
            $update->andWhere('a.client = :client')->setParameter('client', $application->getIdentifier());
            $codes->andWhere('c.client = :client')->setParameter('client', $application->getIdentifier());
        }

        $ids = array_column($accessTokens->getQuery()->getScalarResult(), 'identifier');

        if ([] !== $ids) {
            $this->em->createQueryBuilder()
                ->update(RefreshToken::class, 'r')
                ->set('r.revoked', ':revoked')
                ->where('r.accessToken IN (:ids)')
                ->setParameter('revoked', true)
                ->setParameter('ids', $ids)
                ->getQuery()->execute();
        }

        $update->getQuery()->execute();
        $codes->getQuery()->execute();
    }

    /**
     * Révoque les jetons et prévient les applications concernées (back-channel logout) :
     * celle indiquée, ou toutes celles auxquelles l'utilisateur a accès.
     */
    public function revokeForUser(User $user, ?Application $application = null): void
    {
        $this->revoke($user->getUserIdentifier(), $application);
        $this->backchannelLogout->notify($user, null !== $application ? [$application] : $user->getApplications());
    }
}
