<?php

namespace App\Controller\Admin;

use App\Entity\Application;
use App\Service\AccessRevoker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Image;

#[IsGranted('ROLE_ADMIN')]
class ApplicationCrudController extends AbstractCrudController
{
    public const LOGO_BASE_PATH = 'uploads/logos/';

    public function __construct(
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private readonly PasswordHasherInterface $secretHasher,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly AccessRevoker $revoker,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Application::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Application')
            ->setEntityLabelInPlural('Applications')
            ->setSearchFields(['name', 'identifier', 'description'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $regenerate = Action::new('regenerateSecret', 'Régénérer le secret', 'fa fa-key')
            ->linkToCrudAction('regenerateSecret')
            ->displayIf(static fn (Application $application): bool => $application->isConfidential())
            ->askConfirmation('L\'ancien secret de « %entity_name% » cessera immédiatement de fonctionner. Continuer ?');

        $preview = Action::new('previewLogin', 'Aperçu de la page de connexion', 'fa fa-eye')
            ->linkToCrudAction('previewLogin')
            ->setHtmlAttributes(['target' => '_blank']);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $preview)
            ->add(Crud::PAGE_EDIT, $preview)
            ->add(Crud::PAGE_DETAIL, $regenerate)
            ->add(Crud::PAGE_EDIT, $regenerate);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('identifier', 'Client ID')
            ->setFormTypeOption('disabled', Crud::PAGE_NEW !== $pageName)
            ->setRequired(false)
            ->setHelp(Crud::PAGE_NEW === $pageName ? 'Laisser vide pour générer un identifiant aléatoire (ex. « app1 » si vous préférez un identifiant lisible).' : '');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield UrlField::new('homeUrl', 'URL de l\'application')
            ->setHelp('Ex. https://app1.mydomain.com — affichée sur le portail et autorisée pour la redirection après déconnexion.');
        yield TextareaField::new('redirectUrisText', 'Redirect URIs')
            ->setHelp('Une URL par ligne, ex. https://app1.mydomain.com/auth/callback')
            ->hideOnIndex();
        yield UrlField::new('backchannelLogoutUri', 'URL de déconnexion back-channel')
            ->setHelp('Facultatif (recommandé). Ex. https://app1.mydomain.com/auth/backchannel-logout — l\'application y reçoit un « logout token » dès qu\'un utilisateur est bloqué, déconnecté partout ou perd l\'accès, et ferme sa session immédiatement.')
            ->hideOnIndex();
        yield BooleanField::new('publicClient', 'Client public (sans secret)')
            ->setHelp('À cocher pour une SPA / application mobile : PKCE obligatoire, pas de client secret. Laisser décoché pour une application serveur.')
            ->renderAsSwitch(false)
            ->onlyWhenCreating();
        yield BooleanField::new('confidential', 'Confidentiel')->hideOnForm()->renderAsSwitch(false);
        yield BooleanField::new('active', 'Active')->renderAsSwitch(false);
        yield BooleanField::new('openRegistration', 'Inscription ouverte')
            ->setHelp('Si coché, un utilisateur qui s\'inscrit depuis cette application y a accès immédiatement. Sinon, un administrateur doit lui attribuer l\'accès.')
            ->renderAsSwitch(false);
        yield AssociationField::new('users', 'Utilisateurs')
            ->setFormTypeOptions(['by_reference' => false])
            ->autocomplete()
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();

        yield FormField::addFieldset('Personnalisation de la page de connexion')
            ->setHelp('Appliquée aux pages de connexion, d\'inscription et « accès refusé » quand l\'utilisateur arrive depuis cette application.');
        yield ImageField::new('logo', 'Logo')
            ->setBasePath(self::LOGO_BASE_PATH)
            ->setUploadDir('public/'.self::LOGO_BASE_PATH)
            ->setUploadedFileNamePattern('[slug]-[contenthash].[extension]')
            ->setFileConstraints(new Image(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], mimeTypesMessage: 'Formats acceptés : PNG, JPEG, WebP, GIF.'))
            ->setHelp('PNG, JPEG, WebP ou GIF, 2 Mo maximum. Hauteur affichée : 64 px.')
            ->setRequired(false);
        yield ColorField::new('primaryColor', 'Couleur principale')
            ->setHelp('Boutons et liens (couleur par défaut : #2563eb).')
            ->setRequired(false)
            ->hideOnIndex();
        yield ColorField::new('backgroundColor', 'Couleur de fond')
            ->setHelp('Fond de la page (couleur par défaut : #f3f4f6).')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextareaField::new('loginMessage', 'Message d\'accueil')
            ->setHelp('Ex. « Bienvenue sur l\'intranet de l\'association ». 500 caractères max.')
            ->hideOnIndex();
    }

    public function createEntity(string $entityFqcn): Application
    {
        return new Application('', '', null);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof Application);

        $identifier = $entityInstance->getIdentifier();
        if ('' === $identifier) {
            $identifier = bin2hex(random_bytes(16));
        }

        $secret = $entityInstance->isPublicClient() ? null : bin2hex(random_bytes(32));

        // L'identifiant est la clé primaire : on reconstruit l'entité avec les bonnes valeurs.
        $application = (new Application($entityInstance->getName(), $identifier, null === $secret ? null : $this->secretHasher->hash($secret)))
            ->setDescription($entityInstance->getDescription())
            ->setHomeUrl($entityInstance->getHomeUrl())
            ->setOpenRegistration($entityInstance->isOpenRegistration())
            ->setLogo($entityInstance->getLogo())
            ->setPrimaryColor($entityInstance->getPrimaryColor())
            ->setBackgroundColor($entityInstance->getBackgroundColor())
            ->setLoginMessage($entityInstance->getLoginMessage())
            ->setRedirectUris(...$entityInstance->getRedirectUris())
            ->setBackchannelLogoutUri($entityInstance->getBackchannelLogoutUri())
            ->setGrants(new Grant(OAuth2Grants::AUTHORIZATION_CODE), new Grant(OAuth2Grants::REFRESH_TOKEN))
            ->setActive($entityInstance->isActive());
        // Le formulaire a lié l'instance d'origine (non persistée) aux utilisateurs : on la détache avant de lier la nouvelle.
        foreach ($entityInstance->getUsers()->toArray() as $user) {
            $user->removeApplication($entityInstance);
            $application->addUser($user);
        }

        parent::persistEntity($entityManager, $application);

        $this->addFlash('success', \sprintf('Application créée. Client ID : %s', $identifier));
        if (null !== $secret) {
            $this->addFlash('warning', \sprintf('Client secret (copiez-le maintenant, il ne sera plus jamais affiché) : %s', $secret));
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof Application);

        $users = $entityInstance->getUsers();
        $removed = $users instanceof PersistentCollection ? $users->getDeleteDiff() : [];

        parent::updateEntity($entityManager, $entityInstance);

        foreach ($removed as $user) {
            $this->revoker->revokeForUser($user, $entityInstance);
        }
    }

    /**
     * Affiche la page de connexion telle que la verront les utilisateurs de cette application.
     */
    #[AdminRoute('/{entityId}/preview-login', name: 'preview_login')]
    public function previewLogin(AdminContext $context): Response
    {
        $application = $context->getEntity()->getInstance();
        \assert($application instanceof Application);

        return $this->render('security/login.html.twig', [
            'last_username' => '',
            'error' => null,
            'application' => $application,
            'preview' => true,
        ]);
    }

    #[AdminRoute('/{entityId}/regenerate-secret', name: 'regenerate_secret')]
    public function regenerateSecret(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $application = $context->getEntity()->getInstance();
        \assert($application instanceof Application);

        if ($application->isConfidential()) {
            $secret = bin2hex(random_bytes(32));
            $application->setSecret($this->secretHasher->hash($secret));
            $entityManager->flush();

            $this->addFlash('warning', \sprintf('Nouveau client secret (copiez-le maintenant, il ne sera plus jamais affiché) : %s', $secret));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($application->getIdentifier())
            ->generateUrl());
    }
}
