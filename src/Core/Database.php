<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = strtolower(trim((string) Env::get('DB_CONNECTION', 'sqlite')));
        $dsn = null;
        $user = null;
        $pass = null;

        if ($driver === 'sqlite') {
            $path = (string) Env::get('DB_SQLITE_PATH', 'storage/dev.sqlite');
            if ($path === '') {
                Response::json(['error' => 'Database connection failed'], 500);
                exit;
            }

            // Allow relative paths (resolve from Backend root).
            if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
                $path = realpath(__DIR__ . '/../../') . '/' . $path;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $dsn = 'sqlite:' . $path;
            $user = null;
            $pass = null;
        } else {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $name = Env::get('DB_NAME', '');
            $user = Env::get('DB_USER', '');
            $pass = Env::get('DB_PASS', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        try {
            self::$pdo = new PDO((string) $dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            Response::json(['error' => 'Database connection failed: ' . $e->getMessage()], 500);
            exit;
        }

        if ($driver === 'sqlite') {
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::bootstrapSqlite(self::$pdo);
        }

        return self::$pdo;
    }

    private static function bootstrapSqlite(PDO $pdo): void
    {
        try {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users' LIMIT 1");
            $hasUsers = $stmt !== false ? $stmt->fetchColumn() : false;
            if (!$hasUsers) {
                $schemaPath = __DIR__ . '/../../database/schema.sqlite.sql';
                if (is_file($schemaPath)) {
                    $sql = file_get_contents($schemaPath);
                    if ($sql !== false && trim($sql) !== '') {
                        // Strip comments and execute commands split by semicolon.
                        $lines = preg_split('/\R/', $sql);
                        if (is_array($lines)) {
                            $filtered = [];
                            foreach ($lines as $line) {
                                $t = ltrim($line);
                                if ($t === '' || str_starts_with($t, '--')) {
                                    continue;
                                }
                                $filtered[] = $line;
                            }
                            $pdo->exec(implode("\n", $filtered));
                        } else {
                            $pdo->exec($sql);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log or ignore sqlite bootstrapping failure to prevent crash
            ExceptionHandler::logThrowable($e, 'DatabaseBoot');
        }
    }
}
