<?php

namespace App\Tests\Functional;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportUsersTest extends FunctionalTestCase
{
    /** Hash bcryptjs (préfixe $2a$, coût 10) de « MonVieuxMdp! », comme en produisent les applications Node.js. */
    private const BCRYPTJS_HASH = '$2a$10$aDDpjrtzFTwDmcUd5ZbB3urSGODL4zNFBZcd356.EiU.KTPPiqeCG';

    /**
     * @param list<array<string, string>> $entries
     * @param array<string, mixed>        $options
     */
    private function import(array $entries, array $options = []): CommandTester
    {
        $file = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($file, json_encode($entries));

        $tester = new CommandTester((new ConsoleApplication(static::$kernel))->find('app:user:import'));
        $tester->execute(['file' => $file, ...$options]);
        unlink($file);

        return $tester;
    }

    public function testImportedUserKeepsPasswordAndGetsAccess(): void
    {
        $this->createApplication();

        $tester = $this->import(
            [['email' => 'Carol@Example.com', 'username' => 'carol', 'password' => self::BCRYPTJS_HASH]],
            ['--grant' => self::CLIENT_ID],
        );

        $tester->assertCommandIsSuccessful();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('carol@example.com');
        self::assertNotNull($user);
        self::assertSame('carol', $user->getUsername());
        self::assertCount(1, $user->getApplications());
        $hasher = static::getContainer()->get('security.user_password_hasher');
        self::assertTrue($hasher->isPasswordValid($user, 'MonVieuxMdp!'));
        self::assertFalse($hasher->isPasswordValid($user, 'autre'));
    }

    public function testInvalidUsernameIsFixedAndDuplicatesAreNumbered(): void
    {
        $this->createUser('bob@example.com');

        $tester = $this->import([
            ['email' => 'bob2@example.com', 'username' => 'Bob', 'password' => self::BCRYPTJS_HASH],
            ['email' => 'bob3@example.com', 'username' => 'bob', 'password' => self::BCRYPTJS_HASH],
            ['email' => 'jean.dupont@example.com', 'username' => 'Jean Dupont é', 'password' => 'pas-un-hash'],
        ]);

        $tester->assertCommandIsSuccessful();
        $users = static::getContainer()->get(UserRepository::class);
        self::assertSame('Bob2', $users->findOneByEmail('bob2@example.com')?->getUsername());
        self::assertSame('bob3', $users->findOneByEmail('bob3@example.com')?->getUsername());
        $jean = $users->findOneByEmail('jean.dupont@example.com');
        self::assertSame('JeanDupont', $jean?->getUsername());
        self::assertStringContainsString('mot de passe à réinitialiser', $tester->getDisplay());
        self::assertFalse(static::getContainer()->get('security.user_password_hasher')->isPasswordValid($jean, 'pas-un-hash'));
    }

    public function testExistingAccountIsLeftUntouched(): void
    {
        $this->createApplication();
        $alice = $this->createUser('alice@example.com');
        $password = $alice->getPassword();

        $tester = $this->import(
            [['email' => 'alice@example.com', 'username' => 'autre', 'password' => self::BCRYPTJS_HASH]],
            ['--grant' => self::CLIENT_ID],
        );

        $tester->assertCommandIsSuccessful();
        $this->em()->clear();
        $alice = static::getContainer()->get(UserRepository::class)->findOneByEmail('alice@example.com');
        self::assertSame($password, $alice->getPassword());
        self::assertSame('alice', $alice->getUsername());
        self::assertCount(1, $alice->getApplications());
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->import(
            [['email' => 'dave@example.com', 'username' => 'dave', 'password' => self::BCRYPTJS_HASH]],
            ['--dry-run' => true],
        );

        $tester->assertCommandIsSuccessful();
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('dave@example.com'));
    }

    public function testUnknownApplicationFails(): void
    {
        $tester = $this->import([['email' => 'dave@example.com']], ['--grant' => 'inconnue']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
