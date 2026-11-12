<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112085130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Session : financeur (OPCO, France Travail…).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE session_formation ADD financeur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE session_formation ADD CONSTRAINT FK_3A264B55D17678C FOREIGN KEY (financeur_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3A264B55D17678C ON session_formation (financeur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE session_formation DROP FOREIGN KEY FK_3A264B55D17678C');
        $this->addSql('DROP INDEX IDX_3A264B55D17678C ON session_formation');
        $this->addSql('ALTER TABLE session_formation DROP financeur_id');
    }
}
