<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111142756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Tables formateur, client, participant et inscription ; client et formateurs d'une session.";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, nom VARCHAR(200) NOT NULL, siret VARCHAR(14) NOT NULL, adresse VARCHAR(255) NOT NULL, code_postal VARCHAR(10) NOT NULL, ville VARCHAR(100) NOT NULL, contact_nom VARCHAR(200) NOT NULL, contact_fonction VARCHAR(100) NOT NULL, contact_email VARCHAR(180) NOT NULL, contact_telephone VARCHAR(30) NOT NULL, notes LONGTEXT NOT NULL, archive TINYINT NOT NULL, cree_le DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE formateur (id INT AUTO_INCREMENT NOT NULL, prenom VARCHAR(100) NOT NULL, nom VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, telephone VARCHAR(30) NOT NULL, statut VARCHAR(20) NOT NULL, societe VARCHAR(200) NOT NULL, siret VARCHAR(14) NOT NULL, nda VARCHAR(20) NOT NULL, specialites LONGTEXT NOT NULL, parcours LONGTEXT NOT NULL, notes LONGTEXT NOT NULL, archive TINYINT NOT NULL, cree_le DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE formateur_formation (formateur_id INT NOT NULL, formation_id INT NOT NULL, INDEX IDX_52449B08155D8F51 (formateur_id), INDEX IDX_52449B085200282E (formation_id), PRIMARY KEY (formateur_id, formation_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE inscription (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, session_id INT NOT NULL, participant_id INT NOT NULL, client_id INT DEFAULT NULL, INDEX IDX_5E90F6D6613FECDF (session_id), INDEX IDX_5E90F6D69D1C3019 (participant_id), INDEX IDX_5E90F6D619EB6921 (client_id), UNIQUE INDEX UNIQ_INSCRIPTION (session_id, participant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE participant (id INT AUTO_INCREMENT NOT NULL, prenom VARCHAR(100) NOT NULL, nom VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, telephone VARCHAR(30) NOT NULL, fonction VARCHAR(150) NOT NULL, amenagements LONGTEXT NOT NULL, notes LONGTEXT NOT NULL, archive TINYINT NOT NULL, cree_le DATETIME NOT NULL, client_id INT DEFAULT NULL, INDEX IDX_D79F6B1119EB6921 (client_id), INDEX IDX_PARTICIPANT_NOM (nom, prenom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE session_formation_formateur (session_formation_id INT NOT NULL, formateur_id INT NOT NULL, INDEX IDX_103AB5EB9C9D95AF (session_formation_id), INDEX IDX_103AB5EB155D8F51 (formateur_id), PRIMARY KEY (session_formation_id, formateur_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE formateur_formation ADD CONSTRAINT FK_52449B08155D8F51 FOREIGN KEY (formateur_id) REFERENCES formateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE formateur_formation ADD CONSTRAINT FK_52449B085200282E FOREIGN KEY (formation_id) REFERENCES formation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inscription ADD CONSTRAINT FK_5E90F6D6613FECDF FOREIGN KEY (session_id) REFERENCES session_formation (id)');
        $this->addSql('ALTER TABLE inscription ADD CONSTRAINT FK_5E90F6D69D1C3019 FOREIGN KEY (participant_id) REFERENCES participant (id)');
        $this->addSql('ALTER TABLE inscription ADD CONSTRAINT FK_5E90F6D619EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE participant ADD CONSTRAINT FK_D79F6B1119EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE session_formation_formateur ADD CONSTRAINT FK_103AB5EB9C9D95AF FOREIGN KEY (session_formation_id) REFERENCES session_formation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE session_formation_formateur ADD CONSTRAINT FK_103AB5EB155D8F51 FOREIGN KEY (formateur_id) REFERENCES formateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE session_formation ADD client_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE session_formation ADD CONSTRAINT FK_3A264B519EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3A264B519EB6921 ON session_formation (client_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE formateur_formation DROP FOREIGN KEY FK_52449B08155D8F51');
        $this->addSql('ALTER TABLE formateur_formation DROP FOREIGN KEY FK_52449B085200282E');
        $this->addSql('ALTER TABLE inscription DROP FOREIGN KEY FK_5E90F6D6613FECDF');
        $this->addSql('ALTER TABLE inscription DROP FOREIGN KEY FK_5E90F6D69D1C3019');
        $this->addSql('ALTER TABLE inscription DROP FOREIGN KEY FK_5E90F6D619EB6921');
        $this->addSql('ALTER TABLE participant DROP FOREIGN KEY FK_D79F6B1119EB6921');
        $this->addSql('ALTER TABLE session_formation_formateur DROP FOREIGN KEY FK_103AB5EB9C9D95AF');
        $this->addSql('ALTER TABLE session_formation_formateur DROP FOREIGN KEY FK_103AB5EB155D8F51');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE formateur');
        $this->addSql('DROP TABLE formateur_formation');
        $this->addSql('DROP TABLE inscription');
        $this->addSql('DROP TABLE participant');
        $this->addSql('DROP TABLE session_formation_formateur');
        $this->addSql('ALTER TABLE session_formation DROP FOREIGN KEY FK_3A264B519EB6921');
        $this->addSql('DROP INDEX IDX_3A264B519EB6921 ON session_formation');
        $this->addSql('ALTER TABLE session_formation DROP client_id');
    }
}
