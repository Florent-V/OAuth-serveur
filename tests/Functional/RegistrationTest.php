<?php

namespace App\Tests\Functional;

use App\Repository\UserRepository;

class RegistrationTest extends FunctionalTestCase
{
    private function register(string $email, ?string $username = null): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Créer mon compte')->form([
            'registration_form[username]' => $username ?? strstr($email, '@', true),
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
        ]);
        $this->client->submit($form);
    }

    /**
     * Après l'inscription, un code est envoyé par e-mail : cela vérifie aussi que l'adresse est valide.
     */
    private function registerAndVerify(string $email): void
    {
        $this->register($email);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseRedirects('/2fa');
        $this->completeMfa(mb_strtolower($email));
    }

    public function testRegistrationCreatesAccountAndLogsIn(): void
    {
        $this->registerAndVerify('bob@example.com');

        self::assertResponseRedirects('/');
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('bob@example.com');
        self::assertNotNull($user);
        self::assertSame('bob', $user->getUsername());
        self::assertCount(0, $user->getApplications());

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bonjour bob');
    }

    public function testRegisteringFromASecondApplicationWithExistingEmailAsksToLogIn(): void
    {
        $this->createApplication('app2', 'Application 2');
        $this->createUser('bob@example.com');

        // Arrive depuis app2
        $this->client->request('GET', $this->authorizeUrl('app2'));
        $this->register('Bob@Example.com');

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Vous êtes déjà inscrit');
        self::assertSame('bob@example.com', $crawler->filter('#username')->attr('value'));
        self::assertSelectorTextContains('.app-badge', 'Application 2');
        self::assertCount(1, static::getContainer()->get(UserRepository::class)->findAll());
    }

    public function testRegistrationFromClosedApplicationDoesNotGrantAccess(): void
    {
        $this->createApplication();

        $this->client->request('GET', $this->authorizeUrl());
        $this->registerAndVerify('carol@example.com');
        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.alert-danger', 'Vous n\'avez pas accès');
    }

    public function testRegistrationFromOpenApplicationGrantsAccess(): void
    {
        $this->createApplication(openRegistration: true);

        $this->client->request('GET', $this->authorizeUrl());
        $this->registerAndVerify('dave@example.com');
        $this->client->followRedirect();

        self::assertResponseRedirects();
        self::assertStringStartsWith(self::REDIRECT_URI.'?code=', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testPasswordConfirmationMustMatch(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Créer mon compte')->form([
            'registration_form[username]' => 'eve',
            'registration_form[email]' => 'eve@example.com',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => 'autre-chose-123456',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'ne correspondent pas');
    }

    public function testUsernameMustBeUniqueCaseInsensitive(): void
    {
        $this->createUser('bob@example.com');

        $this->register('other@example.com', 'BOB');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Ce pseudo est déjà pris');
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('other@example.com'));
    }

    public function testUsernameRejectsInvalidCharacters(): void
    {
        $this->register('zoe@example.com', 'zoé@home');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'ne peut contenir que');
    }
}
