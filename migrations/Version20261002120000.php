<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Back-channel logout : URL de déconnexion par application';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_client ADD backchannel_logout_uri VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_client DROP backchannel_logout_uri');
    }
}
