<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Database
 *
 * Lightweight PDO wrapper. The spec called for repository pattern;
 * repositories use this class so business logic never touches PDO directly.
 */
final class Database
{
    private ?PDO $pdo = null;
    private array $config;
    private string $prefix = '';

    public function __construct(array $config)
    {
        $this->config = $config;

        // A prefix lets several sites share one database, which is the norm on
        // cheap shared hosting where you get a fixed number of databases.
        // Restricted to word characters because it is interpolated straight
        // into identifiers — anything else would be an injection point, and a
        // silently mangled table name is worse than a loud failure.
        $raw = (string) ($config['prefix'] ?? '');
        if ($raw !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $raw)) {
            throw new \RuntimeException(
                'DB_PREFIX may only contain letters, numbers and underscores; got "' . $raw . '"'
            );
        }
        $this->prefix = $raw;
    }

    /** The configured table prefix, or '' when there is none. */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Prefix a bare table name: table('posts') -> 'wp_posts'.
     *
     * Idempotent — a name that already carries the prefix is returned as-is, so
     * passing an already-resolved name cannot double it up.
     */
    public function table(string $name): string
    {
        if ($this->prefix === '' || str_starts_with($name, $this->prefix)) {
            return $name;
        }
        return $this->prefix . $name;
    }

    /**
     * Expand {table} tokens in raw SQL to backticked, prefixed identifiers.
     *
     *     SELECT * FROM {posts} WHERE id = :id   ->   SELECT * FROM `wp_posts` ...
     *
     * Only tokens matching a table-name shape are touched, and the expansion is
     * a plain string substitution rather than any attempt to parse SQL —
     * parsing SQL to find table names is how you end up rewriting a string
     * literal that happened to look like a FROM clause.
     *
     * With no prefix configured the output is byte-identical to writing the
     * name directly, which is why existing installs see no change at all.
     */
    public function expand(string $sql): string
    {
        return self::applyPrefix($sql, $this->prefix);
    }

    /**
     * Instance-free token expansion.
     *
     * The migration runners in UpdateService and install.php talk to PDO
     * directly — install.php runs before the container exists at all — so they
     * cannot call expand() on a Database object. Without this they would send
     * "{migrations}" to MySQL verbatim and fail on a syntax error, which is
     * exactly what happened the first time this was wired up.
     */
    public static function applyPrefix(string $sql, string $prefix): string
    {
        if (!str_contains($sql, '{')) {
            return $sql;
        }
        // Two forms, because a table name appears in SQL in two different
        // roles. {posts} is an identifier and gets backticks. {@posts} is the
        // name used as a VALUE — information_schema lookups compare against a
        // string — so it expands bare, ready to sit inside existing quotes.
        $sql = preg_replace_callback(
            '/\{@([a-z][a-z0-9_]*)\}/',
            static fn(array $m): string => $prefix . $m[1],
            $sql
        ) ?? $sql;

        return preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            static fn(array $m): string => '`' . $prefix . $m[1] . '`',
            $sql
        ) ?? $sql;
    }

    public function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $driver = $this->config['driver'] ?? 'mysql';
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $socket = $this->config['socket'] ?? null;
        $database = $this->config['database'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        // Prefer socket if provided (some shared hosts require it)
        if ($socket) {
            $dsn = "{$driver}:unix_socket={$socket};dbname={$database};charset={$charset}";
        } else {
            $dsn = "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";
        }

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'] ?? '',
                $this->config['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$this->config['collation']} ",
                ]
            );
        } catch (PDOException $e) {
            // The driver message embeds host, database and username. It belongs
            // in the log, not in an exception that may surface in a response.
            error_log('Basehim DB connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed.');
        }

        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->connection()->prepare($this->expand($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function insert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->table($table),
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->connection()->prepare($sql);
        foreach ($data as $col => $val) {
            $stmt->bindValue(':' . $col, $val, $this->pdoType($val));
        }
        $stmt->execute();
        return $this->connection()->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $k => $v) {
            $set[] = "`{$k}` = :set_{$k}";
            $params["set_{$k}"] = $v;
        }
        $whereClauses = [];
        foreach ($where as $k => $v) {
            $whereClauses[] = "`{$k}` = :where_{$k}";
            $params["where_{$k}"] = $v;
        }
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->table($table),
            implode(', ', $set),
            implode(' AND ', $whereClauses)
        );
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $whereClauses = [];
        $params = [];
        foreach ($where as $k => $v) {
            $whereClauses[] = "`{$k}` = :{$k}";
            $params[$k] = $v;
        }
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $this->table($table), implode(' AND ', $whereClauses));
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function transaction(\Closure $callback): mixed
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function lastInsertId(): string
    {
        return $this->connection()->lastInsertId();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->connection()->prepare($this->expand($sql));
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
