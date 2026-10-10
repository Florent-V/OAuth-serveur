<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930182701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personnalisation des pages de connexion par application (logo, couleurs, message)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth2_client ADD logo VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth2_client ADD primary_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth2_client ADD background_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth2_client ADD login_message TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth2_client DROP logo');
        $this->addSql('ALTER TABLE oauth2_client DROP primary_color');
        $this->addSql('ALTER TABLE oauth2_client DROP background_color');
        $this->addSql('ALTER TABLE oauth2_client DROP login_message');
    }
}
