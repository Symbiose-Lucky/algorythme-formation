<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111153606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table organisme (identité et signature reprises sur les documents générés) et origine d'un document généré.";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE organisme (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(200) NOT NULL, forme_juridique VARCHAR(200) NOT NULL, adresse VARCHAR(255) NOT NULL, code_postal VARCHAR(10) NOT NULL, ville VARCHAR(100) NOT NULL, siret VARCHAR(14) NOT NULL, rcs VARCHAR(100) NOT NULL, nda VARCHAR(20) NOT NULL, nda_region VARCHAR(100) NOT NULL, telephone VARCHAR(30) NOT NULL, email VARCHAR(180) NOT NULL, representant_nom VARCHAR(200) NOT NULL, representant_qualite VARCHAR(100) NOT NULL, taux_tva DOUBLE PRECISION DEFAULT NULL, conditions_reglement LONGTEXT NOT NULL, conditions_annulation LONGTEXT NOT NULL, signature LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document ADD origine VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE organisme');
        $this->addSql('ALTER TABLE document DROP origine');
    }
}
