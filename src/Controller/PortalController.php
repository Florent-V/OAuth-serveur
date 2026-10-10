<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Portail : liste les applications auxquelles l'utilisateur connecté a accès.
 */
#[IsGranted('ROLE_USER')]
class PortalController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('portal/index.html.twig', [
            'applications' => $user->getApplications()->filter(static fn ($app) => $app->isActive()),
        ]);
    }
}
