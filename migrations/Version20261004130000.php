<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression du nom affiché : le pseudo est le seul nom de l\'utilisateur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP display_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD display_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('UPDATE "user" SET display_name = username');
        $this->addSql('ALTER TABLE "user" ALTER display_name SET NOT NULL');
    }
}
