<?php

namespace App\Tests\Functional;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;

class ResetPasswordTest extends FunctionalTestCase
{
    private const NEW_PASSWORD = 'Nouveau-mot-de-passe-2026';

    private function requestReset(string $email): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->selectButton('Envoyer le lien')->form([
            'reset_password_request_form[email]' => $email,
        ]));
    }

    private function resetUrlFromEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        preg_match('#https?://\S+/reset-password/reset/\S+#', (string) $email->getTextBody(), $m);
        self::assertNotEmpty($m);

        return $m[0];
    }

    private function choosePassword(string $url, string $password = self::NEW_PASSWORD): void
    {
        $this->client->request('GET', $url);
        // Le jeton est retiré de l'URL
        self::assertResponseRedirects('/reset-password/reset');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Enregistrer le mot de passe')->form([
            'change_password_form[plainPassword][first]' => $password,
            'change_password_form[plainPassword][second]' => $password,
        ]));
    }

    public function testLoginPageLinksToPasswordReset(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertCount(1, $crawler->filter('a[href="/reset-password"]'));
    }

    public function testFullPasswordReset(): void
    {
        $user = $this->createUser();

        $this->requestReset('Alice@Example.com');
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'alice@example.com');
        self::assertEmailAddressContains($email, 'From', 'no-reply@mydomain.com');
        $url = $this->resetUrlFromEmail();

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'Si un compte existe');

        $this->choosePassword($url);
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Votre mot de passe a été modifié');
        self::assertSame('alice@example.com', $crawler->filter('#username')->attr('value'));

        // Toutes les sessions / appareils de confiance ont été invalidés
        $this->em()->clear();
        $fresh = $this->em()->find(User::class, $user->getId());
        self::assertSame(1, $fresh->getSessionVersion());
        self::assertSame(1, $fresh->getTrustedTokenVersion());

        // L'ancien mot de passe ne fonctionne plus, le nouveau oui (avec le code MFA)
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'alice@example.com', '_password' => self::PASSWORD]));
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        $this->login(password: self::NEW_PASSWORD);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testLinkCanOnlyBeUsedOnce(): void
    {
        $this->createUser();
        $this->requestReset('alice@example.com');
        $url = $this->resetUrlFromEmail();

        $this->choosePassword($url);
        $this->client->request('GET', $url);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'invalide ou a expiré');
    }

    public function testExpiredLinkIsRejected(): void
    {
        $this->createUser();
        $this->requestReset('alice@example.com');
        $url = $this->resetUrlFromEmail();

        $this->em()->createQuery('UPDATE '.ResetPasswordRequest::class.' r SET r.expiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->execute();

        $this->client->request('GET', $url);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    public function testUnknownOrBlockedAccountGetsSameAnswerWithoutEmail(): void
    {
        $this->createUser('blocked@example.com');
        $this->em()->createQuery('UPDATE '.User::class.' u SET u.enabled = false')->execute();

        foreach (['nobody@example.com', 'blocked@example.com'] as $email) {
            $this->requestReset($email);
            self::assertEmailCount(0);
            self::assertResponseRedirects('/reset-password/check-email');
        }
    }

    public function testRequestsAreThrottledPerAccount(): void
    {
        $this->createUser();

        $this->requestReset('alice@example.com');
        self::assertEmailCount(1);

        $this->requestReset('alice@example.com');
        self::assertEmailCount(0);
        self::assertResponseRedirects('/reset-password/check-email');
    }

    public function testBadTokenIsRejected(): void
    {
        $this->client->request('GET', '/reset-password/reset/nimportequoi');
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }
}
