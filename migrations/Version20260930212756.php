<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930212756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MFA par e-mail, appareils de confiance et révocation des sessions';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD email_auth_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD email_auth_code_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD trusted_token_version INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD session_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP email_auth_code');
        $this->addSql('ALTER TABLE "user" DROP email_auth_code_expires_at');
        $this->addSql('ALTER TABLE "user" DROP trusted_token_version');
        $this->addSql('ALTER TABLE "user" DROP session_version');
    }
}
