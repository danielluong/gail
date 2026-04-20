<?php

namespace App\Ai\Database;

use App\Ai\Database\Dto\MySqlCredentials;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Opens a fresh PDO connection for each tool call using the
 * conversation's stored credentials. A dedicated PDO (rather than a
 * Laravel connection bound in config) keeps agent state out of the
 * framework's connection manager and lets us enforce defense-in-depth:
 *
 *  - SET SESSION TRANSACTION READ ONLY blocks writes at the MySQL
 *    session layer even if the DB user has full privileges.
 *  - Exception-mode errors surface as readable strings to the model.
 *  - A short connect/read timeout prevents a wedged host from hanging
 *    a chat stream.
 *
 * Statement execution itself still goes through SqlSafetyValidator —
 * this class only ensures the database cannot act on an unsafe
 * statement if one ever slipped past the validator.
 */
class MySqlConnectionFactory
{
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const QUERY_TIMEOUT_SECONDS = 30;

    public function open(MySqlCredentials $credentials): PDO
    {
        if (! extension_loaded('pdo_mysql')) {
            throw new RuntimeException('pdo_mysql extension is not installed on this server.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $credentials->host,
            $credentials->port,
            $credentials->database,
        );

        try {
            $pdo = new PDO($dsn, $credentials->username, $credentials->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Could not connect to MySQL: '.$this->sanitizeError($e), previous: $e);
        }

        $this->applyQueryTimeout($pdo);

        return $pdo;
    }

    /**
     * Cap query execution time as a best-effort second line of defense
     * against runaway queries. MySQL 5.7.8+ uses MAX_EXECUTION_TIME (ms);
     * MariaDB uses max_statement_time (seconds, float). Older servers
     * support neither — in that case we simply rely on LIMIT, the
     * connect timeout, and the agent's 5-minute response budget, and
     * carry on. Failures here must never break a connection that would
     * otherwise work.
     */
    private function applyQueryTimeout(PDO $pdo): void
    {
        try {
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME='.(self::QUERY_TIMEOUT_SECONDS * 1000));

            return;
        } catch (PDOException) {
            // Not MySQL 5.7.8+ — fall through and try the MariaDB form.
        }

        try {
            $pdo->exec('SET SESSION max_statement_time='.self::QUERY_TIMEOUT_SECONDS);
        } catch (PDOException) {
            // Neither dialect accepted it; leave the timeout unset.
        }
    }

    private function sanitizeError(PDOException $e): string
    {
        $message = $e->getMessage();

        return preg_replace('/\b(password|pwd)=[^\s;]+/i', '$1=***', $message) ?? $message;
    }
}
