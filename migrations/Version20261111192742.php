<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111192742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table evaluation : positionnement, satisfaction à chaud et à froid, évaluation des acquis.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evaluation (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, reponses JSON NOT NULL, saisi_par VARCHAR(180) DEFAULT NULL, horodatage DATETIME NOT NULL, ip VARCHAR(45) DEFAULT NULL, navigateur VARCHAR(255) DEFAULT NULL, inscription_id INT NOT NULL, formateur_id INT DEFAULT NULL, INDEX IDX_1323A5755DAC5993 (inscription_id), INDEX IDX_1323A575155D8F51 (formateur_id), UNIQUE INDEX UNIQ_EVALUATION (inscription_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A5755DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id)');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575155D8F51 FOREIGN KEY (formateur_id) REFERENCES formateur (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A5755DAC5993');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A575155D8F51');
        $this->addSql('DROP TABLE evaluation');
    }
}
