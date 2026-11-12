<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112124846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domaines : une liste gérée dans l\'admin, reliée aux formations (reprend les domaines déjà saisis).';
    }

    public function up(Schema $schema): void
    {
        // InnoDB explicite : le serveur local crée encore des tables MyISAM,
        // et une clé étrangère ne peut viser qu'une table InnoDB.
        $this->addSql('CREATE TABLE domaine (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, UNIQUE INDEX UNIQ_DOMAINE_NOM (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        // Un domaine par valeur déjà saisie. La comparaison ignore la casse
        // (collation du serveur) : deux graphies ne font qu'un domaine.
        $this->addSql("INSERT IGNORE INTO domaine (nom) SELECT DISTINCT TRIM(domaine) FROM formation WHERE TRIM(domaine) <> '' ORDER BY TRIM(domaine)");

        $this->addSql('ALTER TABLE formation ADD domaine_id INT DEFAULT NULL');
        $this->addSql('UPDATE formation f INNER JOIN domaine d ON d.nom = TRIM(f.domaine) SET f.domaine_id = d.id');
        $this->addSql('ALTER TABLE formation DROP domaine');
        $this->addSql('CREATE INDEX IDX_404021BF4272FC9F ON formation (domaine_id)');
        $this->addSql('ALTER TABLE formation ADD CONSTRAINT FK_404021BF4272FC9F FOREIGN KEY (domaine_id) REFERENCES domaine (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE formation DROP FOREIGN KEY FK_404021BF4272FC9F');
        $this->addSql('DROP INDEX IDX_404021BF4272FC9F ON formation');
        $this->addSql("ALTER TABLE formation ADD domaine VARCHAR(120) NOT NULL DEFAULT ''");
        $this->addSql('UPDATE formation f INNER JOIN domaine d ON d.id = f.domaine_id SET f.domaine = d.nom');
        $this->addSql('ALTER TABLE formation DROP domaine_id');
        $this->addSql('DROP TABLE domaine');
    }
}
