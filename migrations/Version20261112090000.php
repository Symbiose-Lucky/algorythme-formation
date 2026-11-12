<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrite à la main (la base locale était arrêtée) : les noms d'index et de
 * clés sont ceux que Doctrine aurait générés.
 */
final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Evaluation : rattachement à la session, avis des clients et bilans des formateurs (inscription facultative).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation ADD session_id INT DEFAULT NULL, ADD client_id INT DEFAULT NULL, CHANGE inscription_id inscription_id INT DEFAULT NULL');
        // Les lignes déjà présentes portent toutes sur un participant : leur session est celle de l'inscription.
        $this->addSql('UPDATE evaluation e INNER JOIN inscription i ON i.id = e.inscription_id SET e.session_id = i.session_id');
        $this->addSql('ALTER TABLE evaluation CHANGE session_id session_id INT NOT NULL');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575613FECDF FOREIGN KEY (session_id) REFERENCES session_formation (id)');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A57519EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('CREATE INDEX IDX_1323A575613FECDF ON evaluation (session_id)');
        $this->addSql('CREATE INDEX IDX_1323A57519EB6921 ON evaluation (client_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A575613FECDF');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A57519EB6921');
        $this->addSql('DROP INDEX IDX_1323A575613FECDF ON evaluation');
        $this->addSql('DROP INDEX IDX_1323A57519EB6921 ON evaluation');
        $this->addSql('ALTER TABLE evaluation DROP session_id, DROP client_id, CHANGE inscription_id inscription_id INT NOT NULL');
    }
}
