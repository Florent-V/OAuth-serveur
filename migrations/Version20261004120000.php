<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pseudo unique par utilisateur (choisi à l\'inscription)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD username VARCHAR(30) DEFAULT NULL');
        // Comptes existants : pseudo déduit de l'e-mail, suffixé par l'id pour garantir l'unicité.
        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET username = LEFT(regexp_replace(split_part(email, '@', 1), '[^A-Za-z0-9._-]', '', 'g'), 20) || '_' || id
            SQL);
        $this->addSql('ALTER TABLE "user" ALTER username SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_username ON "user" (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_username');
        $this->addSql('ALTER TABLE "user" DROP username');
    }
}
