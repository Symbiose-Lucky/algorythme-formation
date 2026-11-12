<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111140208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Tables session_formation, seance et entree_journal : sessions planifiées, leurs créneaux, et le journal des modifications.";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE entree_journal (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(40) NOT NULL, objet_id INT NOT NULL, action VARCHAR(20) NOT NULL, changements JSON NOT NULL, motif VARCHAR(255) DEFAULT NULL, auteur_email VARCHAR(180) DEFAULT NULL, cree_le DATETIME NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_5D25CD5260BB6FE6 (auteur_id), INDEX IDX_JOURNAL_OBJET (type, objet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance (id INT AUTO_INCREMENT NOT NULL, jour DATE NOT NULL, debut TIME NOT NULL, fin TIME NOT NULL, session_id INT NOT NULL, INDEX IDX_DF7DFD0E613FECDF (session_id), INDEX IDX_SEANCE_JOUR (jour), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE session_formation (id INT AUTO_INCREMENT NOT NULL, formule VARCHAR(20) NOT NULL, modalite VARCHAR(20) NOT NULL, statut VARCHAR(20) NOT NULL, lieu VARCHAR(255) NOT NULL, effectif_max INT DEFAULT NULL, notes LONGTEXT NOT NULL, date_debut DATE DEFAULT NULL, date_fin DATE DEFAULT NULL, cree_le DATETIME NOT NULL, formation_id INT NOT NULL, programme_id INT NOT NULL, INDEX IDX_3A264B55200282E (formation_id), INDEX IDX_3A264B562BB7AEE (programme_id), INDEX IDX_SESSION_DATES (date_debut, date_fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entree_journal ADD CONSTRAINT FK_5D25CD5260BB6FE6 FOREIGN KEY (auteur_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0E613FECDF FOREIGN KEY (session_id) REFERENCES session_formation (id)');
        $this->addSql('ALTER TABLE session_formation ADD CONSTRAINT FK_3A264B55200282E FOREIGN KEY (formation_id) REFERENCES formation (id)');
        $this->addSql('ALTER TABLE session_formation ADD CONSTRAINT FK_3A264B562BB7AEE FOREIGN KEY (programme_id) REFERENCES formation_version (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entree_journal DROP FOREIGN KEY FK_5D25CD5260BB6FE6');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0E613FECDF');
        $this->addSql('ALTER TABLE session_formation DROP FOREIGN KEY FK_3A264B55200282E');
        $this->addSql('ALTER TABLE session_formation DROP FOREIGN KEY FK_3A264B562BB7AEE');
        $this->addSql('DROP TABLE entree_journal');
        $this->addSql('DROP TABLE seance');
        $this->addSql('DROP TABLE session_formation');
    }
}
