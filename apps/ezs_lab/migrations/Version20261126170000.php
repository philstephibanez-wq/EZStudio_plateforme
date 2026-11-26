<?php

declare(strict_types=1);
namespace EZSLabMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
final class Version20261126170000 extends AbstractMigration
{
    public function getDescription(): string { return 'LAB: immutable PROFILE expertise prompt revisions'; }
    public function up(Schema $schema): void
    {
        $this->abortIf(! $schema->hasTable('songs') || ! $schema->hasTable('scientific_runs'), 'Not a LAB database');
        $this->addSql(<<<'SQL'
CREATE TABLE profile_expertise_prompt_revision (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    content TEXT NOT NULL CHECK(length(trim(content)) > 0),
    sha256 VARCHAR(64) NOT NULL CHECK(length(sha256) = 64),
    created_at VARCHAR(40) NOT NULL
);
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE profile_expertise_prompt_active (
    id INTEGER PRIMARY KEY NOT NULL CHECK(id = 1),
    revision_id INTEGER NOT NULL REFERENCES profile_expertise_prompt_revision(id) ON DELETE RESTRICT
);
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER profile_expertise_prompt_immutable_update BEFORE UPDATE ON profile_expertise_prompt_revision BEGIN SELECT RAISE(ABORT, 'prompt_revision_immutable'); END;
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER profile_expertise_prompt_immutable_delete BEFORE DELETE ON profile_expertise_prompt_revision BEGIN SELECT RAISE(ABORT, 'prompt_revision_immutable'); END;
SQL);
    }
    public function down(Schema $schema): void { throw new IrreversibleMigration('Preserve prompt history; restore a verified backup if required.'); }
}
