<?php

namespace App\Security\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\Generator\CodeGeneratorInterface;

/**
 * Génère un code à 6 chiffres (000000–999999), valable 10 minutes (voir User::MFA_CODE_TTL),
 * l'enregistre sur l'utilisateur et l'envoie par e-mail.
 */
final class EmailCodeGenerator implements CodeGeneratorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: AuthCodeMailer::class)]
        private readonly AuthCodeMailerInterface $mailer,
    ) {
    }

    public function generateAndSend(TwoFactorInterface $user): void
    {
        $user->setEmailAuthCode(str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT));
        $this->em->flush();
        $this->mailer->sendAuthCode($user);
    }

    /**
     * « Renvoyer le code » : on génère un nouveau code (l'ancien devient invalide).
     */
    public function reSend(TwoFactorInterface $user): void
    {
        $this->generateAndSend($user);
    }
}
