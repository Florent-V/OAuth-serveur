<?php

namespace App\Controller;

use App\Service\PendingApplicationResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, PendingApplicationResolver $pending): Response
    {
        if ($this->getUser()) {
            return $this->redirect($pending->getTargetPathFromSession($request) ?? $this->generateUrl('app_home'));
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'application' => $pending->resolve($request),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le pare-feu (clé logout).');
    }
}
