<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\Mfa\MfaBruteForceListener;
use Symfony\Component\Mime\Address;

class MfaTest extends FunctionalTestCase
{
    private function submitPassword(string $email = 'alice@example.com'): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function submitCode(string $code): void
    {
        $crawler = $this->client->request('GET', '/2fa');
        $this->client->submit($crawler->selectButton('Valider')->form(['_auth_code' => $code]));
    }

    public function testLoginSendsSixDigitCodeByEmail(): void
    {
        $this->createUser();
        $this->submitPassword();

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'alice@example.com');
        self::assertEmailAddressContains($email, 'From', 'no-reply@mydomain.com');
        $code = $this->currentMfaCode('alice@example.com');
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertEmailSubjectContains($email, $code);
        self::assertEmailHtmlBodyContains($email, $code);

        // Tant que le code n'est pas saisi, les pages protégées renvoient vers /2fa
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa');

        $this->submitCode($code);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour alice');
    }

    public function testWrongCodeIsRejected(): void
    {
        $this->createUser();
        $this->submitPassword();
        $code = $this->currentMfaCode('alice@example.com');

        $this->submitCode('000000' === $code ? '111111' : '000000');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa');
    }

    public function testExpiredCodeIsRejected(): void
    {
        $this->createUser();
        $this->submitPassword();
        $code = $this->currentMfaCode('alice@example.com');

        $this->em()->createQuery('UPDATE '.User::class.' u SET u.emailAuthCodeExpiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->execute();

        $this->submitCode($code);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa');
    }

    public function testBruteForceInvalidatesCode(): void
    {
        $this->createUser();
        $this->submitPassword();
        $code = $this->currentMfaCode('alice@example.com');
        $wrong = '000000' === $code ? '111111' : '000000';

        for ($i = 0; $i < 5; ++$i) {
            $this->submitCode($wrong);
        }
        // 6e essai, même avec le bon code : refusé (throttling de connexion puis invalidation du code)
        $this->submitCode($code);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'tentatives');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa');
    }

    public function testCodeIsInvalidatedAfterTooManyAttemptsFromSeveralIps(): void
    {
        $this->createUser();
        $this->submitPassword();
        $code = $this->currentMfaCode('alice@example.com');
        $wrong = '000000' === $code ? '111111' : '000000';

        // Attaque répartie : IP différente à chaque essai (le throttling par IP ne suffit pas)
        for ($i = 0; $i < 5; ++$i) {
            $this->client->setServerParameter('REMOTE_ADDR', '10.0.0.'.($i + 1));
            $this->submitCode($wrong);
        }
        $this->client->setServerParameter('REMOTE_ADDR', '10.0.0.99');
        $this->submitCode($code);
        $this->client->followRedirect();
        self::assertSame(MfaBruteForceListener::TOO_MANY_ATTEMPTS, $this->client->getCrawler()->filter('.alert-danger')->text());
        self::assertSame('', $this->currentMfaCode('alice@example.com'));
    }

    public function testResendGeneratesNewCode(): void
    {
        $this->createUser();
        $this->submitPassword();
        $oldCode = $this->currentMfaCode('alice@example.com');

        $crawler = $this->client->request('GET', '/2fa');
        $this->client->submit($crawler->selectButton('Je n\'ai pas reçu le code : renvoyer')->form());
        self::assertEmailCount(1);
        self::assertResponseRedirects('/2fa');

        $newCode = $this->currentMfaCode('alice@example.com');
        self::assertMatchesRegularExpression('/^\d{6}$/', $newCode);
        if ($newCode !== $oldCode) {
            $this->submitCode($oldCode);
            $this->client->request('GET', '/');
            self::assertResponseRedirects('/2fa');
        }

        $this->submitCode($newCode);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testTrustedDeviceSkipsCodeUntilRevoked(): void
    {
        $user = $this->createUser();

        $this->login(trustDevice: true);
        $this->client->request('GET', '/logout');

        // Même navigateur : pas de nouveau code
        $this->submitPassword();
        self::assertEmailCount(0);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // Après « Déconnecter partout », l'appareil n'est plus de confiance
        static::getContainer()->get(\App\Service\UserRevoker::class)->logoutEverywhere($this->em()->find(User::class, $user->getId()));
        $this->client->request('GET', '/login');
        $this->submitPassword();
        self::assertEmailCount(1);
    }

    public function testUntrustedDeviceAsksCodeEveryTime(): void
    {
        $this->createUser();

        $this->login(trustDevice: false);
        $this->client->request('GET', '/logout');

        $this->submitPassword();
        self::assertEmailCount(1);
    }
}
