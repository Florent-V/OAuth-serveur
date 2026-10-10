<?php

namespace App\Tests\Functional;

use App\Entity\Application;
use App\Entity\User;

class AdminTest extends FunctionalTestCase
{
    public function testRegularUserCannotAccessAdmin(): void
    {
        $this->createUser();
        $this->login();

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanManageUsersAndApplications(): void
    {
        $this->createApplication();
        $this->createUser('admin@example.com', roles: ['ROLE_ADMIN']);
        $this->login('admin@example.com');

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/user');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'admin@example.com');

        $this->client->request('GET', '/admin/application');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Application 1');

        $this->client->request('GET', '/admin/application/new');
        self::assertResponseIsSuccessful();
    }

    public function testPortalListsGrantedApplications(): void
    {
        $application = $this->createApplication();
        $this->createUser(applications: [$application]);
        $this->login();

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.apps', 'Application 1');
    }

    public function testAdminCreatesApplicationAndGrantsAccessToUser(): void
    {
        $this->createUser('admin@example.com', roles: ['ROLE_ADMIN']);
        $alice = $this->createUser('alice@example.com');
        $this->login('admin@example.com');

        // Création d'une application
        $crawler = $this->client->request('GET', '/admin/application/new');
        $form = $crawler->filter('form[name="Application"]')->form([
            'Application[name]' => 'Wiki',
            'Application[identifier]' => 'wiki',
            'Application[homeUrl]' => 'https://wiki.mydomain.test',
            'Application[redirectUrisText]' => "https://wiki.mydomain.test/callback\nhttps://wiki.mydomain.test/callback2",
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Client secret');

        $application = $this->em()->find(Application::class, 'wiki');
        self::assertNotNull($application);
        self::assertTrue($application->isConfidential());
        self::assertCount(2, $application->getRedirectUris());

        // Attribution de l'application à Alice depuis la fiche utilisateur
        $crawler = $this->client->request('GET', '/admin/user/'.$alice->getId().'/edit');
        $form = $crawler->filter('form[name="User"]')->form();
        $values = $form->getPhpValues();
        $values['User']['applications']['autocomplete'] = ['wiki'];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $this->em()->clear();
        $alice = $this->em()->find(User::class, $alice->getId());
        self::assertTrue($alice->hasAccessTo($this->em()->find(Application::class, 'wiki')));
    }
}
