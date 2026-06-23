<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "This script can only be run via the CLI.\n";
    exit(1);
}

// 1. Register PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

use App\Core\Env;
use App\Core\Database;

Env::load(__DIR__ . '/../.env');

try {
    echo "Connecting to the database...\n";
    $pdo = Database::connection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Connected successfully to [{$driver}].\n";

    // 2. Create the migrations tracking table if not exists
    if ($driver === 'sqlite') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // 3. Scan the migrations directory for files matching the database driver subfolder (<driver>/*.sql)
    $migrationsDir = __DIR__ . '/migrations';
    $driverDir = $migrationsDir . '/' . $driver;
    if (!is_dir($driverDir)) {
        mkdir($driverDir, 0755, true);
    }

    $files = glob($driverDir . '/*.sql');
    if ($files === false) {
        $files = [];
    }

    // Sort files chronologically
    sort($files);

    // Get list of already executed migrations
    $stmt = $pdo->query("SELECT migration FROM migrations");
    $ranMigrations = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $ranMigrationsMap = array_flip($ranMigrations);

    $executedCount = 0;

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($ranMigrationsMap[$name])) {
            continue;
        }

        echo "Migrating: {$name}...\n";

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new Exception("Could not read migration file: {$name}");
        }

        // Run the migration inside a transaction
        $pdo->beginTransaction();
        try {
            // Split raw SQL by semicolon to execute queries individually
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $query = trim($query);
                if ($query === '') {
                    continue;
                }

                try {
                    $pdo->exec($query);
                } catch (PDOException $e) {
                    // Ignore duplicate column, table, index, or key errors to allow re-runs/bootstraps
                    $msg = $e->getMessage();
                    $ignore = str_contains($msg, 'Duplicate column name') || 
                              str_contains($msg, 'duplicate column') || 
                              str_contains($msg, 'already exists') ||
                              str_contains($msg, 'Duplicate key name') ||
                              str_contains($msg, 'Duplicate index');
                    
                    if (!$ignore) {
                        throw $e;
                    }
                }
            }

            // Record execution
            $insertStmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (:migration)");
            $insertStmt->execute(['migration' => $name]);

            $pdo->commit();
            echo "Migrated:  {$name} (Success)\n";
            $executedCount++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($executedCount === 0) {
        echo "No pending migrations found. Database is up to date.\n";
    } else {
        echo "Database migration finished successfully. {$executedCount} migration(s) applied.\n";
    }

} catch (Throwable $e) {
    echo "\nFATAL ERROR: Migration failed!\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    exit(1);
}
