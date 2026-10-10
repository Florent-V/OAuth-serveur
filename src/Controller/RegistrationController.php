<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Service\PendingApplicationResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Security $security,
        PendingApplicationResolver $pending,
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        $application = $pending->resolve($request);

        if ($this->getUser()) {
            $this->addFlash('info', 'Vous êtes déjà connecté : votre compte unique vous permet d\'accéder à toutes vos applications.');

            return $this->redirect($pending->getTargetPathFromSession($request) ?? $this->generateUrl('app_home'));
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Compte déjà existant (inscription depuis une 2e application, par exemple) :
            // on invite l'utilisateur à se connecter avec son compte existant.
            if ('' !== $user->getEmail() && null !== $users->findOneByEmail($user->getEmail())) {
                $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $user->getEmail());
                $this->addFlash('warning', 'Vous êtes déjà inscrit avec cette adresse e-mail. Le même compte sert pour toutes les applications : connectez-vous.');

                return $this->redirectToRoute('app_login');
            }

            if ($form->isValid()) {
                if (!$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                    $this->addFlash('danger', 'Trop d\'inscriptions depuis votre adresse, réessayez plus tard.');

                    return $this->redirectToRoute('app_register');
                }

                $user->setPassword($hasher->hashPassword($user, (string) $user->getPlainPassword()));
                $user->eraseCredentials();

                // Accès automatique uniquement si l'application l'autorise ; sinon un admin devra l'attribuer.
                if (null !== $application && $application->isOpenRegistration()) {
                    $user->addApplication($application);
                }

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Votre compte a été créé.');

                // Connexion automatique ; le gestionnaire de succès du form_login renvoie vers
                // /authorize?... si l'utilisateur venait d'une application, sinon vers le portail.
                return $security->login($user, 'form_login', PendingApplicationResolver::FIREWALL)
                    ?? $this->redirectToRoute('app_home');
            }
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form,
            'application' => $application,
        ]);
    }
}
