<?php

namespace App\Security\Mfa;

use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Condition\TwoFactorConditionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Active / désactive globalement la MFA avec la variable d'environnement MFA_ENABLED.
 */
final class MfaCondition implements TwoFactorConditionInterface
{
    public function __construct(
        #[Autowire(env: 'bool:MFA_ENABLED')]
        private readonly bool $enabled,
    ) {
    }

    public function shouldPerformTwoFactorAuthentication(AuthenticationContextInterface $context): bool
    {
        return $this->enabled;
    }
}
