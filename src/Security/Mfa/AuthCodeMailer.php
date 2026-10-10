<?php

namespace App\Security\Mfa;

use App\Entity\User;
use App\Service\PendingApplicationResolver;
use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Envoie le code MFA. L'expéditeur (From) est défini globalement dans config/packages/mailer.yaml
 * à partir des variables d'environnement MAILER_FROM_EMAIL / MAILER_FROM_NAME.
 */
final class AuthCodeMailer implements AuthCodeMailerInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RequestStack $requestStack,
        private readonly PendingApplicationResolver $pending,
    ) {
    }

    public function sendAuthCode(TwoFactorInterface $user): void
    {
        $code = (string) $user->getEmailAuthCode();
        $request = $this->requestStack->getMainRequest();

        $email = (new TemplatedEmail())
            ->to($user->getEmailAuthRecipient())
            ->subject(\sprintf('Votre code de connexion : %s', $code))
            ->htmlTemplate('emails/mfa_code.html.twig')
            ->textTemplate('emails/mfa_code.txt.twig')
            ->context([
                'code' => $code,
                'name' => $user instanceof User ? $user->getUsername() : '',
                'expires_at' => $user instanceof User ? $user->getEmailAuthCodeExpiresAt() : null,
                'application' => null !== $request ? $this->pending->resolve($request) : null,
                'ip' => $request?->getClientIp(),
            ]);

        $this->mailer->send($email);
    }
}
