<?php

namespace Neuedaten\FreezedDesk\Storage;

use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * The SQLite connection. Opened read-only for a build (DeskSource) and
 * read-write for the CLI and the UI; migrations run on demand and are
 * versioned with SQLite's user_version pragma.
 */
final class Database
{
    private ?\PDO $pdo = null;

    public function __construct(
        public readonly string $path,
        public readonly bool $readOnly = false,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->readOnly && !$this->exists()) {
            throw new DeskException(sprintf(
                'Desk database %s does not exist yet. Run "freezed-desk migrate" (or open the desk UI once) to create it.',
                $this->path
            ));
        }

        if (!$this->readOnly) {
            $directory = dirname($this->path);
            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new DeskException('Could not create the data folder ' . $directory . '.');
            }
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if ($this->readOnly) {
            // PHP 8.4 moved the SQLite constants to Pdo\Sqlite; the old
            // names are deprecated since 8.5.
            if (class_exists(\Pdo\Sqlite::class)) {
                $options[\Pdo\Sqlite::ATTR_OPEN_FLAGS] = \Pdo\Sqlite::OPEN_READONLY;
            } else {
                $options[\PDO::SQLITE_ATTR_OPEN_FLAGS] = \PDO::SQLITE_OPEN_READONLY;
            }
        }

        try {
            $pdo = new \PDO('sqlite:' . $this->path, null, null, $options);
        } catch (\PDOException $exception) {
            throw new DeskException('Could not open the desk database ' . $this->path . ': ' . $exception->getMessage());
        }

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        if (!$this->readOnly) {
            $this->migrate($pdo);
        } elseif ($this->userVersion($pdo) < Migrations::latest()) {
            throw new DeskException(sprintf(
                'Desk database %s is at schema version %d, this Desk needs %d. Run "freezed-desk migrate".',
                $this->path,
                $this->userVersion($pdo),
                Migrations::latest()
            ));
        }

        return $this->pdo = $pdo;
    }

    /**
     * Apply pending migrations.
     *
     * @return string[] One message per migration applied.
     */
    public function migrate(?\PDO $pdo = null): array
    {
        $pdo ??= $this->pdo();
        $current = $this->userVersion($pdo);
        $messages = [];

        foreach (Migrations::all() as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                $pdo->exec('PRAGMA user_version = ' . (int) $version);
                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw new DeskException('Migration ' . $version . ' failed: ' . $exception->getMessage());
            }
            $messages[] = 'Applied database migration ' . $version . '.';
        }

        return $messages;
    }

    public function userVersion(?\PDO $pdo = null): int
    {
        $pdo ??= $this->pdo();

        return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    }

    /**
     * Run a callback inside a transaction (nested calls join the outer one).
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }
}
