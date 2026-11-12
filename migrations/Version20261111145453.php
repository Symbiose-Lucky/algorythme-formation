<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111145453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Tables document et signataire : fichiers déposés dans l'admin et leurs signatures.";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, objet_type VARCHAR(40) NOT NULL, objet_id INT NOT NULL, nom VARCHAR(200) NOT NULL, categorie VARCHAR(30) NOT NULL, fichier VARCHAR(255) NOT NULL, nom_original VARCHAR(255) NOT NULL, mime VARCHAR(120) NOT NULL, taille INT NOT NULL, empreinte VARCHAR(64) NOT NULL, depose_le DATETIME NOT NULL, depose_par VARCHAR(180) DEFAULT NULL, archive TINYINT NOT NULL, INDEX IDX_DOCUMENT_OBJET (objet_type, objet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE signataire (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(200) NOT NULL, email VARCHAR(180) NOT NULL, jeton VARCHAR(64) NOT NULL, zones JSON NOT NULL, envoye_le DATETIME DEFAULT NULL, signe_le DATETIME DEFAULT NULL, signature LONGTEXT DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, navigateur VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, document_id INT NOT NULL, INDEX IDX_24248B92C33F7837 (document_id), UNIQUE INDEX UNIQ_SIGNATAIRE_JETON (jeton), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE signataire ADD CONSTRAINT FK_24248B92C33F7837 FOREIGN KEY (document_id) REFERENCES document (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE signataire DROP FOREIGN KEY FK_24248B92C33F7837');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE signataire');
    }
}
