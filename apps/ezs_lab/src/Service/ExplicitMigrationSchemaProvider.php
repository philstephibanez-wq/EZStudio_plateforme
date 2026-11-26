<?php

declare(strict_types=1);
namespace EZSLab\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Provider\SchemaDiffProvider;

/** LAB migrations must declare every SQL statement explicitly. */
final class ExplicitMigrationSchemaProvider implements SchemaDiffProvider
{
    public function __construct(private readonly Connection $connection) {}

    public function createFromSchema(): Schema
    {
        return $this->connection->createSchemaManager()->introspectSchema();
    }

    public function createToSchema(Schema $fromSchema): Schema
    {
        return clone $fromSchema;
    }

    public function getSqlDiffToMigrate(Schema $fromSchema, Schema $toSchema): array
    {
        // Fail closed on migrations using Schema mutation instead of addSql().
        if (serialize($fromSchema) !== serialize($toSchema)) {
            throw new \LogicException('LAB migration must use explicit addSql statements only.');
        }
        return [];
    }
}
