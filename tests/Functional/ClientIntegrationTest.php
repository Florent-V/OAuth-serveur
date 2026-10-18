<?php

namespace App\Tests\Functional;

use App\Entity\Application;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

/**
 * Comportements dont dépendent les applications clientes (voir docs/integrer-une-application.md).
 */
class ClientIntegrationTest extends FunctionalTestCase
{
    private const PUBLIC_CLIENT_ID = 'spa';

    public function testPublicClientMustUsePkce(): void
    {
        $application = $this->createPublicApplication();
        $this->createUser(applications: [$application]);
        $this->client->request('GET', '/login');
        $this->login();

        // Sans code_challenge : refusé
        $this->client->request('GET', $this->publicAuthorizeUrl());
        self::assertResponseStatusCodeSame(400);

        // Avec code_challenge (S256) : code délivré
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->client->request('GET', $this->publicAuthorizeUrl(['code_challenge' => $challenge, 'code_challenge_method' => 'S256']));
        $code = $this->codeFromRedirect();

        // Mauvais code_verifier : refusé
        $this->client->request('POST', '/token', $this->publicTokenParams($code, 'mauvais-verifier-'.str_repeat('x', 43)));
        self::assertResponseStatusCodeSame(400);

        // Bon code_verifier, sans secret : jetons délivrés
        $this->client->request('GET', $this->publicAuthorizeUrl(['code_challenge' => $challenge, 'code_challenge_method' => 'S256']));
        $code = $this->codeFromRedirect();
        $this->client->request('POST', '/token', $this->publicTokenParams($code, $verifier));
        self::assertResponseIsSuccessful();
        $tokens = $this->json();
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertArrayHasKey('refresh_token', $tokens);
    }

    public function testRefreshTokenIsRotated(): void
    {
        $tokens = $this->obtainTokens();

        $this->client->request('POST', '/token', $this->refreshParams($tokens['refresh_token']));
        self::assertResponseIsSuccessful();
        $renewed = $this->json();
        self::assertNotSame($tokens['refresh_token'], $renewed['refresh_token']);
        self::assertNotSame($tokens['access_token'], $renewed['access_token']);

        // L'ancien refresh token ne peut pas être réutilisé ; le nouveau, si
        $this->client->request('POST', '/token', $this->refreshParams($tokens['refresh_token']));
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', '/token', $this->refreshParams($renewed['refresh_token']));
        self::assertResponseIsSuccessful();
    }

    public function testAccessTokenCanBeVerifiedWithThePublicKey(): void
    {
        $tokens = $this->obtainTokens();
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertEqualsWithDelta(15 * 60, $tokens['expires_in'], 5);

        // Vérification locale, comme le ferait une application : signature RS256 + claims
        [$header, $payload, $signature] = explode('.', $tokens['access_token']);
        $decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));
        self::assertSame('RS256', json_decode($decode($header), true)['alg']);
        $publicKey = file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/var/test-keys/public.pem');
        self::assertSame(1, openssl_verify($header.'.'.$payload, $decode($signature), $publicKey, \OPENSSL_ALGO_SHA256));

        $claims = json_decode($decode($payload), true);
        self::assertSame(self::CLIENT_ID, \is_array($claims['aud']) ? $claims['aud'][0] : $claims['aud']);
        self::assertSame('alice@example.com', $claims['sub']);
        self::assertSame('alice', $claims['preferred_username']);
        self::assertSame('alice', $claims['name']);
        self::assertTrue(Uuid::isValid($claims['uid']));
        self::assertInstanceOf(UuidV4::class, Uuid::fromString($claims['uid']));
        self::assertEqualsCanonicalizing(['profile', 'email'], $claims['scopes']);
        self::assertGreaterThan(time(), $claims['exp']);
    }

    public function testClientCanAuthenticateWithHttpBasic(): void
    {
        $tokens = $this->obtainTokens();

        $this->client->request('POST', '/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']], server: [
            'PHP_AUTH_USER' => self::CLIENT_ID,
            'PHP_AUTH_PW' => self::CLIENT_SECRET,
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testUserinfoIsForbiddenWhenApplicationIsDeactivated(): void
    {
        $tokens = $this->obtainTokens();
        $this->em()->find(Application::class, self::CLIENT_ID)->setActive(false);
        $this->em()->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/userinfo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserinfoOnlyReturnsGrantedScopes(): void
    {
        $tokens = $this->obtainTokens(scope: 'email');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/userinfo', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseIsSuccessful();
        $userinfo = $this->json();
        self::assertSame('alice@example.com', $userinfo['email']);
        self::assertArrayNotHasKey('name', $userinfo);
    }

    public function testDeactivatedApplicationIsRefused(): void
    {
        $tokens = $this->obtainTokens();
        $application = $this->em()->find(Application::class, self::CLIENT_ID);
        $application->setActive(false);
        $this->em()->flush();

        // Pas de code : page explicite
        $this->client->request('GET', $this->authorizeUrl());
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.alert-danger', 'est momentanément désactivée');

        $this->client->request('POST', '/token', $this->refreshParams($tokens['refresh_token']));
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array<string, mixed>
     */
    private function obtainTokens(string $scope = 'profile email'): array
    {
        $application = $this->createApplication();
        $this->createUser(applications: [$application]);
        $this->client->request('GET', '/login');
        $this->login();

        $this->client->request('GET', '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => $scope,
            'state' => 'xyz',
        ]));
        $code = $this->codeFromRedirect();

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
        ]);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function createPublicApplication(): Application
    {
        $application = (new Application('SPA', self::PUBLIC_CLIENT_ID, null))
            ->setRedirectUris(new RedirectUri(self::REDIRECT_URI))
            ->setGrants(new Grant(OAuth2Grants::AUTHORIZATION_CODE), new Grant(OAuth2Grants::REFRESH_TOKEN));
        $this->em()->persist($application);
        $this->em()->flush();

        return $application;
    }

    /**
     * @param array<string, string> $extra
     */
    private function publicAuthorizeUrl(array $extra = []): string
    {
        return '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::PUBLIC_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'profile email',
            'state' => 'xyz',
        ] + $extra);
    }

    /**
     * @return array<string, string>
     */
    private function publicTokenParams(string $code, string $verifier): array
    {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => self::PUBLIC_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $verifier,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function refreshParams(string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => $refreshToken,
        ];
    }

    private function codeFromRedirect(): string
    {
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('xyz', $query['state']);

        return (string) $query['code'];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
