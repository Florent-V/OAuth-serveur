<?php

namespace App\Tests\Functional;

use App\Entity\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BrandingTest extends FunctionalTestCase
{
    private function brand(Application $application): void
    {
        $application
            ->setLogo('app1-logo.png')
            ->setPrimaryColor('#E11D48')
            ->setBackgroundColor('#0f172a')
            ->setLoginMessage('Bienvenue sur l\'intranet');
        $this->em()->flush();
    }

    public function testLoginPageUsesApplicationBranding(): void
    {
        $this->brand($this->createApplication());

        $this->client->request('GET', $this->authorizeUrl());
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('img.brand-logo[src="/uploads/logos/app1-logo.png"]');
        self::assertSelectorTextContains('.login-message', 'Bienvenue sur l\'intranet');
        $css = $crawler->filter('head style')->last()->text();
        self::assertStringContainsString('--primary: #e11d48', $css);
        self::assertStringContainsString('--bg-page: #0f172a', $css);

        // La page d'inscription garde la personnalisation
        $this->client->request('GET', '/register');
        self::assertSelectorExists('img.brand-logo');
        self::assertSelectorTextContains('.login-message', 'Bienvenue');
    }

    public function testAccessDeniedPageUsesApplicationBranding(): void
    {
        $this->brand($this->createApplication());
        $this->createUser();

        $this->client->request('GET', $this->authorizeUrl());
        $this->login();
        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('img.brand-logo');
    }

    public function testLoginPageWithoutApplicationIsNotBranded(): void
    {
        $this->brand($this->createApplication());

        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('img.brand-logo');
        self::assertSelectorNotExists('body.branded');
    }

    public function testAdminUploadsLogoSetsColorsAndPreviews(): void
    {
        $this->createApplication();
        $this->createUser('admin@example.com', roles: ['ROLE_ADMIN']);
        $this->login('admin@example.com');

        $png = sys_get_temp_dir().'/logo-test.png';
        // PNG noir 40×20 en dur : pas besoin de l'extension GD pour lancer les tests
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAACgAAAAUCAIAAABwJOjsAAAAGUlEQVR42u3BMQEAAADCoPVPbQo/oAAAeBsJdAAB1C53OAAAAABJRU5ErkJggg=='));

        $crawler = $this->client->request('GET', '/admin/application/'.self::CLIENT_ID.'/edit');
        $form = $crawler->filter('form[name="Application"]')->form();
        $form['Application[primaryColor]'] = '#16a34a';
        $form['Application[loginMessage]'] = 'Espace membres';
        $form['Application[logo][file]']->upload($png);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em()->clear();
        $application = $this->em()->find(Application::class, self::CLIENT_ID);
        self::assertSame('#16a34a', $application->getPrimaryColor());
        self::assertNotNull($application->getLogo());
        $logoPath = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/logos/'.$application->getLogo();
        self::assertFileExists($logoPath);

        // Aperçu de la page de connexion depuis l'admin
        $this->client->request('GET', '/admin/application/'.self::CLIENT_ID.'/preview-login');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.preview-banner', 'Aperçu');
        self::assertSelectorTextContains('.login-message', 'Espace membres');

        (new Filesystem())->remove([$logoPath, $png]);
    }

    public function testAdminRejectsInvalidColorAndNonImageFile(): void
    {
        $this->createApplication();
        $this->createUser('admin@example.com', roles: ['ROLE_ADMIN']);
        $this->login('admin@example.com');

        $crawler = $this->client->request('GET', '/admin/application/'.self::CLIENT_ID.'/edit');
        $form = $crawler->filter('form[name="Application"]')->form();
        $values = $form->getPhpValues();
        $values['Application']['primaryColor'] = 'red;}body{display:none';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);

        $svg = sys_get_temp_dir().'/logo-test.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $crawler = $this->client->request('GET', '/admin/application/'.self::CLIENT_ID.'/edit');
        $form = $crawler->filter('form[name="Application"]')->form();
        $form['Application[logo][file]']->upload($svg);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        unlink($svg);
    }
}
