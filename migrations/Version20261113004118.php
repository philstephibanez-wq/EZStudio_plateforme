<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113004118 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__job_queue AS SELECT id, status, payload, created_at FROM job_queue');
        $this->addSql('DROP TABLE job_queue');
        $this->addSql('CREATE TABLE job_queue (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(20) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, source VARCHAR(50) NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO job_queue (id, status, payload, created_at) SELECT id, status, payload, created_at FROM __temp__job_queue');
        $this->addSql('DROP TABLE __temp__job_queue');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__job_queue AS SELECT id, status, payload, created_at FROM job_queue');
        $this->addSql('DROP TABLE job_queue');
        $this->addSql('CREATE TABLE job_queue (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(50) NOT NULL, payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , job_name VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO job_queue (id, status, payload, created_at) SELECT id, status, payload, created_at FROM __temp__job_queue');
        $this->addSql('DROP TABLE __temp__job_queue');
    }
}
