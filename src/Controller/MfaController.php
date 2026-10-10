<?php

namespace App\Controller;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\Generator\CodeGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class MfaController extends AbstractController
{
    /**
     * Renvoie un nouveau code MFA (limité à 3 envois par quart d'heure).
     */
    #[Route('/2fa/resend', name: 'app_mfa_resend', methods: ['POST'])]
    public function resend(
        Request $request,
        #[Autowire(service: 'scheb_two_factor.security.email.code_generator')]
        CodeGeneratorInterface $codeGenerator,
        RateLimiterFactoryInterface $mfaResendLimiter,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('mfa_resend', $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('2fa_login');
        }

        if ($mfaResendLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $codeGenerator->reSend($user);
            $this->addFlash('success', 'Un nouveau code vous a été envoyé par e-mail.');
        } else {
            $this->addFlash('danger', 'Trop de demandes de code. Patientez quelques minutes avant de réessayer.');
        }

        return $this->redirectToRoute('2fa_login');
    }
}
