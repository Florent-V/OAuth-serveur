<?php

namespace App\Controller\Admin;

use App\Entity\Application;
use App\Entity\User;
use App\Repository\ApplicationRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ApplicationRepository $applications,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'user_count' => $this->users->count([]),
            'application_count' => $this->applications->count([]),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Serveur OAuth2')
            ->setLocales(['fr']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-users');
        yield MenuItem::linkTo(ApplicationCrudController::class, 'Applications', 'fa fa-cubes');
        yield MenuItem::section();
        yield MenuItem::linkToRoute('Retour au portail', 'fa fa-arrow-left', 'app_home');
        yield MenuItem::linkToLogout('Déconnexion', 'fa fa-sign-out');
    }
}
