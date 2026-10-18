<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identifiant public UUIDv4 des utilisateurs (l\'id entier reste la clé primaire interne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD uuid UUID DEFAULT NULL');
        // Comptes existants : UUIDv4 aléatoire (gen_random_uuid() est natif depuis PostgreSQL 13).
        $this->addSql('UPDATE "user" SET uuid = gen_random_uuid() WHERE uuid IS NULL');
        $this->addSql('ALTER TABLE "user" ALTER uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_uuid ON "user" (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_uuid');
        $this->addSql('ALTER TABLE "user" DROP uuid');
    }
}
