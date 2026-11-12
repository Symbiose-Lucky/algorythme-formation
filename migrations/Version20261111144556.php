<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111144556 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table emargement : présence de chaque participant et formateur à chaque séance, avec sa signature.";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE emargement (id INT AUTO_INCREMENT NOT NULL, etat VARCHAR(20) NOT NULL, origine VARCHAR(20) NOT NULL, signature LONGTEXT DEFAULT NULL, horodatage DATETIME NOT NULL, ip VARCHAR(45) DEFAULT NULL, navigateur VARCHAR(255) DEFAULT NULL, saisi_par VARCHAR(180) DEFAULT NULL, seance_id INT NOT NULL, inscription_id INT DEFAULT NULL, formateur_id INT DEFAULT NULL, INDEX IDX_71BBB250E3797A94 (seance_id), INDEX IDX_71BBB2505DAC5993 (inscription_id), INDEX IDX_71BBB250155D8F51 (formateur_id), UNIQUE INDEX UNIQ_EMARGEMENT_PARTICIPANT (seance_id, inscription_id), UNIQUE INDEX UNIQ_EMARGEMENT_FORMATEUR (seance_id, formateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE emargement ADD CONSTRAINT FK_71BBB250E3797A94 FOREIGN KEY (seance_id) REFERENCES seance (id)');
        $this->addSql('ALTER TABLE emargement ADD CONSTRAINT FK_71BBB2505DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id)');
        $this->addSql('ALTER TABLE emargement ADD CONSTRAINT FK_71BBB250155D8F51 FOREIGN KEY (formateur_id) REFERENCES formateur (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE emargement DROP FOREIGN KEY FK_71BBB250E3797A94');
        $this->addSql('ALTER TABLE emargement DROP FOREIGN KEY FK_71BBB2505DAC5993');
        $this->addSql('ALTER TABLE emargement DROP FOREIGN KEY FK_71BBB250155D8F51');
        $this->addSql('DROP TABLE emargement');
    }
}
