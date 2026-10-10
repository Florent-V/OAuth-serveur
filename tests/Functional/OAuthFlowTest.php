<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\AccessRevoker;

class OAuthFlowTest extends FunctionalTestCase
{
    public function testUnauthenticatedUserIsSentToLoginPageShowingTheApplication(): void
    {
        $this->createApplication();

        $this->client->request('GET', $this->authorizeUrl());
        self::assertResponseRedirects('/login');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-badge', 'Application 1');
    }

    public function testUserWithoutAccessSeesAccessDeniedPage(): void
    {
        $this->createApplication();
        $this->createUser();

        $this->client->request('GET', $this->authorizeUrl());
        $this->login();
        // Après connexion, retour automatique vers /authorize
        self::assertResponseRedirects();
        self::assertStringContainsString('/authorize', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.alert-danger', 'Vous n\'avez pas accès à l\'application Application 1');
    }

    public function testFullAuthorizationCodeFlow(): void
    {
        $application = $this->createApplication();
        $this->createUser(applications: [$application]);

        $this->client->request('GET', $this->authorizeUrl());
        $this->login();
        $this->client->followRedirect();

        // Redirection vers l'application avec un code
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('xyz', $query['state']);
        self::assertNotEmpty($query['code']);

        // Échange du code contre un jeton (fait par le backend de l'application)
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $query['code'],
        ]);
        self::assertResponseIsSuccessful();
        $tokens = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('access_token', $tokens);
        self::assertArrayHasKey('refresh_token', $tokens);

        // Le JWT contient l'e-mail de l'utilisateur
        $payload = json_decode(base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')), true);
        self::assertSame('alice@example.com', $payload['email']);
        self::assertSame(self::CLIENT_ID, \is_array($payload['aud']) ? $payload['aud'][0] : $payload['aud']);
        self::assertSame('alice@example.com', $payload['sub']);

        // Informations utilisateur
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/userinfo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseIsSuccessful();
        $userinfo = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('alice@example.com', $userinfo['email']);
        self::assertSame('alice', $userinfo['preferred_username']);
        self::assertSame('alice', $userinfo['name']);
        self::assertSame('alice@example.com', $userinfo['sub']);
        self::assertSame($payload['uid'], $userinfo['id']);

        // Rafraîchissement du jeton
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testRevokingAccessInvalidatesTokens(): void
    {
        $application = $this->createApplication();
        $user = $this->createUser(applications: [$application]);

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
        $tokens = json_decode((string) $this->client->getResponse()->getContent(), true);

        // L'administrateur retire l'accès
        $user = $this->em()->find(User::class, $user->getId());
        $application = $user->getApplications()->first();
        $user->removeApplication($application);
        $this->em()->flush();
        static::getContainer()->get(AccessRevoker::class)->revokeForUser($user, $application);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/userinfo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testTokenEndpointRejectsWrongSecret(): void
    {
        $this->createApplication();

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'wrong',
            'redirect_uri' => self::REDIRECT_URI,
            'code' => 'whatever',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRedirectsOnlyToKnownApplications(): void
    {
        $application = $this->createApplication();
        $this->createUser(applications: [$application]);

        $this->login();
        $this->client->request('GET', '/logout?redirect_uri='.urlencode('https://app1.mydomain.test/bye'));
        self::assertResponseRedirects('https://app1.mydomain.test/bye');

        $this->login();
        $this->client->request('GET', '/logout?redirect_uri='.urlencode('https://evil.example.com/'));
        self::assertResponseRedirects('/login');
    }
}
