<?php

namespace App\Security\Mfa;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Code MFA à usage unique et protection contre la force brute sur le code à 6 chiffres : au-delà de 5 essais en 15 minutes,
 * le code en cours est invalidé et l'utilisateur doit en demander un nouveau.
 */
final class MfaBruteForceListener
{
    public const TOO_MANY_ATTEMPTS = 'Trop de tentatives : ce code n\'est plus valable. Demandez un nouveau code.';

    public function __construct(
        private readonly RateLimiterFactoryInterface $mfaCheckLimiter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::CHECK)]
    public function onCheck(TwoFactorCodeEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!$this->mfaCheckLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $user->invalidateEmailAuthCode();
            $this->em->flush();

            throw new CustomUserMessageAuthenticationException(self::TOO_MANY_ATTEMPTS);
        }
    }

    /**
     * Code accepté : il est à usage unique, on l'efface et on remet le compteur d'essais à zéro.
     */
    #[AsEventListener(event: TwoFactorAuthenticationEvents::CODE_VALID)]
    public function onValid(TwoFactorCodeEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $user->invalidateEmailAuthCode();
            $this->em->flush();
            $this->mfaCheckLimiter->create((string) $user->getId())->reset();
        }
    }
}
