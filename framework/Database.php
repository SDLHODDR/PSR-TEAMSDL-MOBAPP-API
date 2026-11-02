<?php

declare(strict_types=1);

namespace SDL\Framework;

use Throwable;

final class Database
{
    private mixed $connection = null;
    private bool $inTransaction = false;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $connectionString,
        private readonly string $charset = 'AL32UTF8'
    ) {
    }

    public static function fromEnvironment(): self
    {
        $username = trim((string) getenv('ORACLE_USER'));
        $password = (string) getenv('ORACLE_PASSWORD');
        $connectionString = trim((string) getenv('ORACLE_CONNECTION_STRING'));
        $charset = trim((string) (getenv('ORACLE_CHARSET') ?: 'AL32UTF8'));

        if ($username === '' || $password === '' || $connectionString === '') {
            throw new DatabaseException(
                'Oracle configuration is incomplete. Set ORACLE_USER, ORACLE_PASSWORD and ORACLE_CONNECTION_STRING.'
            );
        }

        if (!function_exists('oci_connect')) {
            throw new DatabaseException('The OCI8 extension is not available in this PHP runtime.');
        }

        return new self($username, $password, $connectionString, $charset);
    }

    /**
     * Lazily creates one non-persistent OCI8 connection for this PHP request.
     * OCI8 connections are request-scoped in the usual Apache PHP model.
     */
    public function connection(): mixed
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (!function_exists('oci_connect')) {
            throw new DatabaseException('The OCI8 extension is not available in this PHP runtime.');
        }

        $connection = @oci_connect(
            $this->username,
            $this->password,
            $this->connectionString,
            $this->charset
        );

        if ($connection === false) {
            // Do not send Oracle error text or connection details to the API caller.
            $oracleError = function_exists('oci_error') ? oci_error() : false;
            error_log('[SDL PSR API] Oracle connection failed: ' .
                (is_array($oracleError) ? (string) ($oracleError['message'] ?? 'unknown Oracle error') : 'unknown Oracle error'));
            throw new DatabaseException('Unable to connect to the Oracle database.');
        }

        $this->connection = $connection;
        return $this->connection;
    }

    /**
     * Execute a SELECT and return all rows as associative arrays.
     * Bind parameters are values, never SQL fragments.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->executeStatement($sql, $params, defined('OCI_NO_AUTO_COMMIT') ? OCI_NO_AUTO_COMMIT : 0);
        $rows = [];

        try {
            while (($row = oci_fetch_assoc($statement)) !== false) {
                $rows[] = $row;
            }
            return $rows;
        } finally {
            oci_free_statement($statement);
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->executeStatement($sql, $params, defined('OCI_NO_AUTO_COMMIT') ? OCI_NO_AUTO_COMMIT : 0);

        try {
            $row = oci_fetch_assoc($statement);
            return $row === false ? null : $row;
        } finally {
            oci_free_statement($statement);
        }
    }

    /**
     * Execute INSERT/UPDATE/DELETE or a PL/SQL block.
     * Call commit() to persist the operation, or use transaction() for automatic
     * commit/rollback. Standalone calls do not auto-commit.
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->executeStatement($sql, $params, defined('OCI_NO_AUTO_COMMIT') ? OCI_NO_AUTO_COMMIT : 0);

        try {
            $count = oci_num_rows($statement);
            return is_int($count) ? $count : 0;
        } finally {
            oci_free_statement($statement);
        }
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new DatabaseException('A transaction is already active on this connection.');
        }
        // Opening the connection first ensures transaction state is known.
        $this->connection();
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new DatabaseException('No active transaction to commit.');
        }

        if (!oci_commit($this->connection())) {
            $this->logLastOracleError('Oracle commit failed');
            $this->rollbackQuietly();
            throw new DatabaseException('Unable to commit the Oracle transaction.');
        }
        $this->inTransaction = false;
    }

    public function rollback(): void
    {
        if (!$this->inTransaction) {
            return;
        }

        if (!oci_rollback($this->connection())) {
            $this->logLastOracleError('Oracle rollback failed');
            $this->inTransaction = false;
            throw new DatabaseException('Unable to roll back the Oracle transaction.');
        }
        $this->inTransaction = false;
    }

    /**
     * Run a callback inside a transaction. Any Throwable triggers rollback.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->rollbackQuietly();
            throw $exception;
        }
    }

    public function ping(): bool
    {
        $row = $this->fetchOne('SELECT 1 AS HEALTH_CHECK FROM DUAL');
        return isset($row['HEALTH_CHECK']) && (string) $row['HEALTH_CHECK'] === '1';
    }

    private function executeStatement(string $sql, array $params, int $mode): mixed
    {
        $connection = $this->connection();
        $statement = @oci_parse($connection, $sql);

        if ($statement === false) {
            $this->logLastOracleError('Oracle SQL parse failed');
            throw new DatabaseException('Unable to prepare the database statement.');
        }

        // OCI8 bind variables bind by reference; retain values until execution completes.
        $boundValues = [];
        try {
            foreach ($params as $key => $value) {
                $placeholder = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
                $boundValues[$placeholder] = $value;
                $bindType = match (true) {
                    is_int($value) => SQLT_INT,
                    is_bool($value) => SQLT_INT,
                    default => SQLT_CHR,
                };

                $ok = @oci_bind_by_name(
                    $statement,
                    $placeholder,
                    $boundValues[$placeholder],
                    -1,
                    $bindType
                );
                if (!$ok) {
                    $this->logStatementError($statement, 'Oracle parameter binding failed');
                    throw new DatabaseException('Unable to bind a database parameter.');
                }
            }

            if (!@oci_execute($statement, $mode)) {
                $this->logStatementError($statement, 'Oracle statement execution failed');
                throw new DatabaseException('The database operation failed.');
            }

            return $statement;
        } catch (Throwable $exception) {
            oci_free_statement($statement);
            throw $exception;
        }
    }

    private function logStatementError(mixed $statement, string $context): void
    {
        $error = oci_error($statement);
        error_log('[SDL PSR API] ' . $context . ': ' .
            (is_array($error) ? (string) ($error['message'] ?? 'unknown Oracle error') : 'unknown Oracle error'));
    }

    private function logLastOracleError(string $context): void
    {
        $error = oci_error($this->connection);
        error_log('[SDL PSR API] ' . $context . ': ' .
            (is_array($error) ? (string) ($error['message'] ?? 'unknown Oracle error') : 'unknown Oracle error'));
    }

    private function rollbackQuietly(): void
    {
        if (!$this->inTransaction || $this->connection === null) {
            return;
        }

        @oci_rollback($this->connection);
        $this->inTransaction = false;
    }

    public function executeReturning(
        string $sql,
        array $params = [],
        string $returnParam = ':return_id'
    ): string {

        $connection = $this->connection();
        $statement = @oci_parse($connection, $sql);

        if ($statement === false) {
            $this->logLastOracleError('Oracle SQL parse failed');
            throw new DatabaseException('Unable to prepare the database statement.');
        }

        $boundValues = [];
        $returnValue = '';

        try {

            // Bind input parameters
            foreach ($params as $key => $value) {

                $placeholder = is_int($key)
                    ? $key + 1
                    : (str_starts_with((string)$key, ':')
                        ? (string)$key
                        : ':' . $key);

                $boundValues[$placeholder] = $value;

                $bindType = match (true) {
                    is_int($value), is_bool($value) => SQLT_INT,
                    default => SQLT_CHR,
                };

                if (!oci_bind_by_name(
                    $statement,
                    $placeholder,
                    $boundValues[$placeholder],
                    -1,
                    $bindType
                )) {
                    $this->logStatementError($statement, 'Oracle parameter binding failed');
                    throw new DatabaseException('Unable to bind a database parameter.');
                }
            }

            // Bind Oracle RETURNING output parameter
            if (!oci_bind_by_name(
                $statement,
                $returnParam,
                $returnValue,
                100,
                SQLT_CHR
            )) {
                $this->logStatementError($statement, 'Oracle output binding failed');
                throw new DatabaseException('Unable to bind the returned ID.');
            }

            // Execute without auto-commit
            if (!oci_execute($statement, OCI_NO_AUTO_COMMIT)) {
                $this->logStatementError($statement, 'Oracle statement execution failed');
                throw new DatabaseException('The database operation failed.');
            }

            return (string)$returnValue;

        } finally {
            oci_free_statement($statement);
        }
    }

    public function __destruct()
    {
        if ($this->inTransaction && $this->connection !== null) {
            $this->rollbackQuietly();
        }
        if ($this->connection !== null && function_exists('oci_close')) {
            @oci_close($this->connection);
        }
    }
}
