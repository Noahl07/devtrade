<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La colonne user.locale est mappée par l'entité User mais n'avait jamais été créée par une migration
 * (elle a été ajoutée hors migration sur certaines bases). IF NOT EXISTS : sans effet là où elle existe déjà.
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilisateur : colonne locale manquante dans les migrations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS locale VARCHAR(5) DEFAULT \'fr\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS locale');
    }
}
