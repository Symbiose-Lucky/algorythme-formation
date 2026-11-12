<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Formation : suppression du champ « En page d'accueil », jamais utilisé par le site.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE formation DROP en_page_accueil');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE formation ADD en_page_accueil TINYINT NOT NULL DEFAULT 0');
    }
}
