<?php

namespace App\Tests\Functional;

use App\Entity\Application;
use App\Entity\User;
use App\Security\BackchannelLogout\BackchannelLogoutNotifier;
use App\Service\AccessRevoker;
use App\Tests\Support\RecordingHttpClient;
use Symfony\Bundle\FrameworkBundle\Console\Application as Console;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * OpenID Connect Back-Channel Logout : les applications sont prévenues dès qu'un utilisateur est révoqué.
 */
class BackchannelLogoutTest extends FunctionalTestCase
{
    private const URI_APP1 = 'https://app1.mydomain.test/auth/backchannel-logout';
    private const URI_APP2 = 'https://app2.mydomain.test/auth/backchannel-logout';
    private const URI_APP4 = 'https://app4.mydomain.test/auth/backchannel-logout';

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        // app1, app2 : URL de back-channel ; app3 : sans URL ; app4 : URL, mais Alice n'y a pas accès
        $app1 = $this->createApplication('app1', 'Application 1')->setBackchannelLogoutUri(self::URI_APP1);
        $app2 = $this->createApplication('app2', 'Application 2')->setBackchannelLogoutUri(self::URI_APP2);
        $app3 = $this->createApplication('app3', 'Application 3');
        $this->createApplication('app4', 'Application 4')->setBackchannelLogoutUri(self::URI_APP4);
        $this->em()->flush();
        $this->alice = $this->createUser(applications: [$app1, $app2, $app3]);
    }

    public function testAdminBlockNotifiesEveryApplicationOfTheUser(): void
    {
        $this->createUser('admin@example.com', roles: ['ROLE_ADMIN']);
        $this->login('admin@example.com');

        $this->client->request('GET', '/admin/user/'.$this->alice->getId().'/block');
        self::assertResponseRedirects();

        // Envoyé après la réponse (kernel.terminate), à app1 et app2 seulement
        self::assertEqualsCanonicalizing([self::URI_APP1, self::URI_APP2], array_column($this->recorder()->requests, 'url'));
    }

    public function testLogoutTokenFollowsTheSpecification(): void
    {
        $this->console(['command' => 'app:user:revoke', 'email' => 'alice@example.com', '--logout-only' => true]);

        $request = $this->requestTo(self::URI_APP1);
        self::assertSame('POST', $request['method']);
        parse_str($request['body'], $form);
        [$header, $claims] = $this->verify($form['logout_token']);

        self::assertSame('RS256', $header['alg']);
        self::assertSame('logout+jwt', $header['typ']);
        // DEFAULT_URI peut être surchargée par l'environnement (http://localhost:8080 dans le conteneur de dev)
        self::assertSame(rtrim((string) $_SERVER['DEFAULT_URI'], '/'), $claims['iss']);
        self::assertSame('app1', \is_array($claims['aud']) ? $claims['aud'][0] : $claims['aud']);
        self::assertSame('alice@example.com', $claims['sub']);
        self::assertSame($this->alice->getId(), $claims['uid']);
        self::assertSame([], (array) $claims['events']['http://schemas.openid.net/event/backchannel-logout']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims['jti']);
        self::assertEqualsWithDelta(time(), $claims['iat'], 5);
        self::assertSame(120, $claims['exp'] - $claims['iat']);
        self::assertArrayNotHasKey('nonce', $claims);
    }

    public function testRemovingAccessNotifiesOnlyThatApplication(): void
    {
        $this->console(['command' => 'app:access:grant', 'email' => 'alice@example.com', 'client_id' => 'app2', '--revoke' => true]);

        self::assertSame([self::URI_APP2], array_column($this->recorder()->requests, 'url'));
    }

    public function testDeletedUserIsStillIdentifiedInTheToken(): void
    {
        $id = $this->alice->getId();
        $alice = $this->em()->find(User::class, $id);
        static::getContainer()->get(AccessRevoker::class)->revokeForUser($alice);
        $this->em()->remove($alice);
        $this->em()->flush();
        static::getContainer()->get(BackchannelLogoutNotifier::class)->flush();

        parse_str($this->requestTo(self::URI_APP1)['body'], $form);
        [, $claims] = $this->verify($form['logout_token']);
        self::assertSame('alice@example.com', $claims['sub']);
        self::assertSame($id, $claims['uid']);
    }

    public function testFailingApplicationIsRetriedWithoutBlockingTheRevocation(): void
    {
        $this->createUser('admin@example.com', roles: ['ROLE_ADMIN']);
        $this->login('admin@example.com');
        $this->client->disableReboot();
        $this->recorder()->failWith(500);

        $this->client->request('GET', '/admin/user/'.$this->alice->getId().'/block');
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertFalse($this->em()->find(User::class, $this->alice->getId())->isEnabled());
        // 1 essai + 2 nouvelles tentatives par application
        self::assertCount(3, array_filter($this->recorder()->requests, static fn (array $r): bool => self::URI_APP1 === $r['url']));
    }

    public function testCommandToTestAnApplicationEndpoint(): void
    {
        $tester = $this->console(['command' => 'app:application:test-backchannel-logout', 'client_id' => 'app1', 'email' => 'alice@example.com']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('HTTP 200', $tester->getDisplay());

        $tester = $this->console(['command' => 'app:application:test-backchannel-logout', 'client_id' => 'app3', 'email' => 'alice@example.com']);
        self::assertSame(1, $tester->getStatusCode());
    }

    public function testBackchannelUriMustBeAnHttpUrl(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $application = new Application('X', 'x', null);

        self::assertCount(1, $validator->validate($application->setBackchannelLogoutUri('ftp://app.test/logout')));
        self::assertCount(0, $validator->validate($application->setBackchannelLogoutUri('http://app1:3000/backchannel-logout')));
    }

    private function recorder(): RecordingHttpClient
    {
        return static::getContainer()->get(RecordingHttpClient::class);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): ApplicationTester
    {
        $console = new Console(static::$kernel);
        $console->setAutoExit(false);
        $tester = new ApplicationTester($console);
        $tester->run($input);

        return $tester;
    }

    /**
     * @return array{method: string, url: string, body: string}
     */
    private function requestTo(string $url): array
    {
        foreach ($this->recorder()->requests as $request) {
            if ($url === $request['url']) {
                return $request;
            }
        }
        self::fail('Aucune requête vers '.$url);
    }

    /**
     * Vérifie la signature RS256 avec la clé publique du serveur, comme le fera l'application.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function verify(string $jwt): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));
        $publicKey = file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/var/test-keys/public.pem');
        self::assertSame(1, openssl_verify($header.'.'.$payload, $decode($signature), $publicKey, \OPENSSL_ALGO_SHA256));

        return [json_decode($decode($header), true), json_decode($decode($payload), true)];
    }
}
