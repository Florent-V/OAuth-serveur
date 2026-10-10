<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\UserRevoker;

class RevocationTest extends FunctionalTestCase
{
    /**
     * Connexion complète à app1 et récupération des jetons OAuth2.
     *
     * @return array{access_token: string, refresh_token: string}
     */
    private function loginAndGetTokens(): array
    {
        $this->client->request('GET', $this->authorizeUrl());
        $this->login();
        $this->client->followRedirect();
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $query['code'],
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function revoker(): UserRevoker
    {
        return static::getContainer()->get(UserRevoker::class);
    }

    public function testLogoutEverywhereKillsWebSessionAndTokens(): void
    {
        $application = $this->createApplication();
        $user = $this->createUser(applications: [$application]);
        $tokens = $this->loginAndGetTokens();

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->revoker()->logoutEverywhere($this->em()->find(User::class, $user->getId()));

        // Session web fermée immédiatement
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Votre session a été fermée');

        // Jetons OAuth2 révoqués
        $this->client->request('GET', '/api/userinfo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(400);

        // Le compte reste utilisable (avec un nouveau code MFA)
        $this->login();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testBlockedUserIsLoggedOutAndCannotLogIn(): void
    {
        $application = $this->createApplication();
        $user = $this->createUser(applications: [$application]);
        $this->loginAndGetTokens();

        $this->revoker()->block($this->em()->find(User::class, $user->getId()));

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => 'alice@example.com',
            '_password' => self::PASSWORD,
        ]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Votre compte est désactivé');
    }

    public function testAdminBlockAction(): void
    {
        $alice = $this->createUser();
        $this->createUser('admin@example.com', roles: ['ROLE_ADMIN']);
        $this->login('admin@example.com');

        $this->client->request('GET', '/admin/user/'.$alice->getId().'/block');
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertFalse($this->em()->find(User::class, $alice->getId())->isEnabled());
    }

    public function testSecurityHeaders(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString("frame-ancestors 'none'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }
}
