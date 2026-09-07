<?php

namespace App\Database;

use DateTimeInterface;
use Illuminate\Database\PostgresConnection;
use PDO;

/**
 * Postgres connection that is safe with PgBouncer transaction pooling when
 * PDO::ATTR_EMULATE_PREPARES is enabled.
 *
 * Emulated prepares otherwise bind PHP booleans as integers (1/0), which
 * PostgreSQL rejects for boolean columns (boolean = integer).
 */
class PgbouncerPostgresConnection extends PostgresConnection
{
    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($this->getQueryGrammar()->getDateFormat());
            } elseif (is_bool($value)) {
                // PostgreSQL needs true/false string literals under emulated prepares.
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }

    /**
     * @param  \PDOStatement  $statement
     * @param  array<int|string, mixed>  $bindings
     */
    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_resource($value) => PDO::PARAM_LOB,
                    default => PDO::PARAM_STR,
                }
            );
        }
    }
}
