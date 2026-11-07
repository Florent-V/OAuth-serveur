<?php

namespace App\Security\Jwt;

use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Décore le dépôt d'access tokens du bundle : les nouveaux jetons portent l'en-tête « kid ».
 * Persistance et révocation restent celles du bundle.
 */
#[AsDecorator(decorates: 'league.oauth2_server.repository.access_token')]
final class KidAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly AccessTokenRepositoryInterface $inner,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SigningKeys $keys,
    ) {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        // Mêmes claims supplémentaires que le dépôt du bundle (voir AccessTokenClaimsSubscriber)
        $event = $this->eventDispatcher->dispatch(
            new AccessTokenExtraClaimsResolveEvent($clientEntity, $scopes, $userIdentifier),
            OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE
        );

        $accessToken = new KidAccessToken($this->keys->activeKid(), $event->getExtraClaims());
        $accessToken->setClient($clientEntity);
        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $accessToken->setUserIdentifier($userIdentifier);
        }
        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->inner->persistNewAccessToken($accessTokenEntity);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->inner->revokeAccessToken($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->inner->isAccessTokenRevoked($tokenId);
    }
}
