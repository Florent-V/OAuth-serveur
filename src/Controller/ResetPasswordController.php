<?php

namespace App\Controller;

use App\Entity\Application;
use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use App\Service\PendingApplicationResolver;
use App\Service\UserRevoker;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * « Mot de passe oublié ».
 *
 * Pour ne pas révéler quelles adresses ont un compte, la réponse est toujours la même
 * (« si un compte existe, un e-mail a été envoyé »).
 */
#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request')]
    public function request(
        Request $request,
        UserRepository $users,
        MailerInterface $mailer,
        PendingApplicationResolver $pending,
        RateLimiterFactoryInterface $passwordResetLimiter,
        LoggerInterface $logger,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$passwordResetLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $this->addFlash('danger', 'Trop de demandes depuis votre adresse. Réessayez dans quelques minutes.');

                return $this->redirectToRoute('app_forgot_password_request');
            }

            $user = $users->findOneByEmail((string) $form->get('email')->getData());

            // Compte inconnu ou bloqué : on ne dit rien, même page de confirmation.
            if (null !== $user && $user->isEnabled()) {
                $this->sendResetEmail($user, $mailer, $pending->resolve($request), $logger);
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', ['form' => $form]);
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        // Jeton factice si aucun e-mail n'a été envoyé : la page est identique dans tous les cas
        if (null === $this->getTokenObjectFromSession()) {
            $this->setTokenObjectInSession($this->resetPasswordHelper->generateFakeResetToken());
        }

        return $this->render('reset_password/check_email.html.twig', [
            'lifetime_minutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $hasher,
        UserRevoker $revoker,
        ?string $token = null,
    ): Response {
        if (null !== $token) {
            // On retire le jeton de l'URL (historique, en-tête Referer, logs du proxy)
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            $this->addFlash('danger', 'Lien de réinitialisation manquant. Faites une nouvelle demande.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->cleanSessionAfterReset();
            $this->addFlash('danger', 'Ce lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le lien ne sert qu'une fois
            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            // Par sécurité : fermeture de toutes les sessions, oubli des appareils de confiance,
            // révocation des jetons OAuth2 (quelqu'un connaissait peut-être l'ancien mot de passe).
            $revoker->logoutEverywhere($user);

            $this->cleanSessionAfterReset();
            $request->getSession()->set('_security.last_username', $user->getEmail());
            $this->addFlash('success', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['form' => $form]);
    }

    private function sendResetEmail(User $user, MailerInterface $mailer, ?Application $application, LoggerInterface $logger): void
    {
        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Demande trop rapprochée pour ce compte : on n'envoie rien, sans le signaler.
            return;
        }

        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->textTemplate('emails/reset_password.txt.twig')
            ->context([
                'name' => $user->getUsername(),
                'reset_url' => $this->generateUrl('app_reset_password', ['token' => $resetToken->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lifetime_minutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
                'application' => $application,
            ]);

        // (efface le jeton en clair de l'objet : à faire après avoir généré l'URL)
        $this->setTokenObjectInSession($resetToken);

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $logger->error('Envoi de l\'e-mail de réinitialisation impossible : {message}', ['message' => $e->getMessage()]);
        }
    }
}
