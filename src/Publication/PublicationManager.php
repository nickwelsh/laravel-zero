<?php

namespace LaravelZero\Publication;

use LaravelZero\Metadata\ModelRegistry;
use LogicException;

final readonly class PublicationManager
{
    public function __construct(private ModelRegistry $registry) {}

    public function plan(string $name): array
    {
        $connection = $this->registry->connection();
        if ($connection->getDriverName() !== 'pgsql' || (int) $connection->selectOne('SHOW server_version_num')->server_version_num < 150000) {
            throw new LogicException('Zero publications require PostgreSQL 15 or later.');
        }
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) {
            throw new LogicException('Invalid publication identifier; underscore prefixes are reserved by Zero.');
        }
        $existing = $connection->selectOne('SELECT puballtables FROM pg_publication WHERE pubname = ?', [$name]);
        $schemaWide = $existing !== null && $connection->selectOne('SELECT 1 FROM pg_publication_namespace n JOIN pg_publication p ON p.oid = n.pnpubid WHERE p.pubname = ?', [$name]) !== null;
        if ($existing?->puballtables || $schemaWide) {
            throw new LogicException('Cannot restrict a publication that publishes all tables or an entire schema. Choose a dedicated Zero publication.');
        }
        $plan = [];
        $publication = $this->quote($name);
        if ($existing === null) {
            $plan[] = 'CREATE PUBLICATION '.$publication;
        }
        $tables = [];
        foreach ($this->registry->all() as $model) {
            $tables[$model->table] = array_keys($model->columns);
        }
        foreach ($this->registry->pivots() as $table => $columns) {
            $tables[$table] = $columns;
        }
        ksort($tables);
        if ($tables === []) {
            throw new LogicException('Register at least one Zero model before syncing a publication.');
        }
        $targets = [];
        foreach ($tables as $table => $columns) {
            $targets[] = 'public.'.$this->quote($table).' ('.implode(', ', array_map($this->quote(...), $columns)).')';
        }
        $plan[] = 'ALTER PUBLICATION '.$publication.' SET TABLE '.implode(', ', $targets);

        return $plan;
    }

    public function sync(string $name): void
    {
        $connection = $this->registry->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Zero publications require PostgreSQL.');
        }
        $connection->transaction(function () use ($connection, $name) {
            $connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$name]);
            foreach ($this->plan($name) as $statement) {
                $connection->statement($statement);
            }
        });
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
