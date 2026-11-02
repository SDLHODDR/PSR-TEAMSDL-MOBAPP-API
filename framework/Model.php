<?php

declare(strict_types=1);

namespace SDL\Framework;

abstract class Model
{
    public function __construct(protected readonly Database $db)
    {
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->db->fetchOne($sql, $params);
    }

    protected function execute(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $params);
    }

    protected function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    protected function executeReturning(
        string $sql,
        array $params = [],
        string $returnParam = ':return_id'
    ): string {

        return $this->db->executeReturning(
            $sql,
            $params,
            $returnParam
        );
    }
}
