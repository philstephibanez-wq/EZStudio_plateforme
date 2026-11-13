<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112234357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create job_queue table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE job_queue (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, job_name VARCHAR(255) NOT NULL, status VARCHAR(50) NOT NULL, payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job_queue');
    }
}
