<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111133605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Tables formation et formation_version : formations gérées dans l'admin, avec l'historique de leurs modifications.";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Une clé étrangère ne peut viser qu'une table InnoDB : selon le
        // serveur, `user` a pu être créée en MyISAM. Sans effet si elle
        // est déjà en InnoDB.
        $this->addSql('ALTER TABLE user ENGINE = InnoDB');
        $this->addSql('CREATE TABLE formation (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, nom VARCHAR(200) NOT NULL, slug VARCHAR(200) NOT NULL, statut VARCHAR(20) NOT NULL, domaine VARCHAR(120) NOT NULL, accroche LONGTEXT NOT NULL, en_page_accueil TINYINT NOT NULL, duree_heures DOUBLE PRECISION DEFAULT NULL, lieu VARCHAR(200) NOT NULL, tarif_inter_ht INT DEFAULT NULL, tarif_intra_ht INT DEFAULT NULL, objectifs LONGTEXT NOT NULL, public_vise LONGTEXT NOT NULL, prerequis LONGTEXT NOT NULL, programme LONGTEXT NOT NULL, ce_qui_change LONGTEXT NOT NULL, methodes LONGTEXT NOT NULL, moyens LONGTEXT NOT NULL, evaluation LONGTEXT NOT NULL, acces LONGTEXT NOT NULL, accessibilite LONGTEXT NOT NULL, profil_formateurs LONGTEXT NOT NULL, note DOUBLE PRECISION DEFAULT NULL, nb_formes INT DEFAULT NULL, smartof_uid VARCHAR(64) DEFAULT NULL, version INT NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, UNIQUE INDEX UNIQ_FORMATION_CODE (code), UNIQUE INDEX UNIQ_FORMATION_SLUG (slug), UNIQUE INDEX UNIQ_FORMATION_SMARTOF (smartof_uid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE formation_version (id INT AUTO_INCREMENT NOT NULL, numero INT NOT NULL, contenu JSON NOT NULL, modifications JSON NOT NULL, motif VARCHAR(255) DEFAULT NULL, auteur_email VARCHAR(180) DEFAULT NULL, cree_le DATETIME NOT NULL, formation_id INT NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_FAE396B85200282E (formation_id), INDEX IDX_FAE396B860BB6FE6 (auteur_id), UNIQUE INDEX UNIQ_FORMATION_VERSION_NUMERO (formation_id, numero), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE formation_version ADD CONSTRAINT FK_FAE396B85200282E FOREIGN KEY (formation_id) REFERENCES formation (id)');
        $this->addSql('ALTER TABLE formation_version ADD CONSTRAINT FK_FAE396B860BB6FE6 FOREIGN KEY (auteur_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE formation_version DROP FOREIGN KEY FK_FAE396B85200282E');
        $this->addSql('ALTER TABLE formation_version DROP FOREIGN KEY FK_FAE396B860BB6FE6');
        $this->addSql('DROP TABLE formation');
        $this->addSql('DROP TABLE formation_version');
    }
}
