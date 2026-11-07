<?php

namespace App\Security\Jwt;

use Lcobucci\JWT\Builder;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Access token identique à celui du bundle (mêmes claims, même signature RS256), avec en plus
 * l'en-tête « kid » qui désigne la clé du JWKS à utiliser pour vérifier la signature.
 *
 * @see \League\Bundle\OAuth2ServerBundle\Entity\AccessToken
 */
final class KidAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait {
        initJwtConfiguration as private traitInitJwtConfiguration;
    }
    use EntityTrait;
    use TokenEntityTrait;

    /**
     * @param array<non-empty-string, mixed> $extraClaims
     */
    public function __construct(
        private readonly string $kid,
        private readonly array $extraClaims = [],
    ) {
    }

    public function initJwtConfiguration(): void
    {
        $this->traitInitJwtConfiguration();

        $builder = $this->jwtConfiguration->builder()->withHeader('kid', $this->kid);
        foreach ($this->extraClaims as $name => $value) {
            if ('scopes' === $name) {
                throw new \InvalidArgumentException('The "scopes" claim is reserved and cannot be used as an extra claim.');
            }
            $builder = $builder->withClaim($name, $value);
        }

        $this->jwtConfiguration = $this->jwtConfiguration->withBuilderFactory(static fn (): Builder => $builder);
    }
}
