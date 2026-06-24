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
use App\Core\Uuid;

// Load environment configuration
Env::load(__DIR__ . '/../.env');

try {
    echo "Connecting to the database...\n";
    $pdo = Database::connection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Connected successfully to [{$driver}].\n";

    // 2. Ensure renter user exists and has 'renter' role
    $userId = 'user-1-uuid';
    echo "Ensuring user [{$userId}] exists with 'renter' role...\n";

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $userExists = $stmt->fetchColumn();

    if (!$userExists) {
        $stmt = $pdo->prepare("
            INSERT INTO users (id, phone, country_code, email, full_name, role, is_active)
            VALUES (:id, '9109322140', '+91', 'user@local.test', 'John Doe', 'renter', 1)
        ");
        $stmt->execute(['id' => $userId]);
        echo "Created user [{$userId}] as 'renter'.\n";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET role = 'renter' WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        echo "Updated user [{$userId}] role to 'renter'.\n";
    }

    // 3. Clear ALL vehicles and images from the database and storage
    echo "Clearing ALL vehicles data, image records, and files from storage...\n";

    // Select all image file paths first to delete files from disk
    $stmt = $pdo->query("SELECT file_path FROM images");
    $allImageFiles = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    foreach ($allImageFiles as $filePath) {
        if ($filePath) {
            $fullPath = __DIR__ . '/../storage/' . $filePath;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    // Delete all rows from vehicles and images tables
    $pdo->exec("DELETE FROM vehicles");
    $pdo->exec("DELETE FROM images");
    echo "All vehicle and image records cleared from the database.\n";

    // Clean up anything remaining in storage/uploads recursively
    $uploadDir = __DIR__ . '/../storage/uploads';
    $absoluteUploadDir = realpath($uploadDir);
    if ($absoluteUploadDir === false) {
        $absoluteUploadDir = $uploadDir;
    }

    $deleteDirRecursive = static function (string $dir) use (&$deleteDirRecursive): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $deleteDirRecursive($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    };

    if (is_dir($absoluteUploadDir)) {
        $deleteDirRecursive($absoluteUploadDir);
    }
    echo "Storage uploads folder recursively cleared.\n";

    // 4. Define vehicle lists with mapped real images
    $bikes = [
        ['make' => 'Royal Enfield', 'model' => 'Classic 350', 'year' => 2022, 'price_day' => 1200.00, 'price_hour' => 100.00, 'image_source' => 'cruiser.png'],
        ['make' => 'Harley-Davidson', 'model' => 'Iron 883', 'year' => 2021, 'price_day' => 4500.00, 'price_hour' => 400.00, 'image_source' => 'cruiser.png'],
        ['make' => 'Honda', 'model' => 'Activa 6G', 'year' => 2023, 'price_day' => 400.00, 'price_hour' => 40.00, 'image_source' => 'scooter.png'],
        ['make' => 'Yamaha', 'model' => 'YZF R15 V4', 'year' => 2023, 'price_day' => 1000.00, 'price_hour' => 90.00, 'image_source' => 'sports_bike.png'],
        ['make' => 'KTM', 'model' => 'Duke 390', 'year' => 2022, 'price_day' => 1800.00, 'price_hour' => 150.00, 'image_source' => 'sports_bike.png'],
        ['make' => 'Suzuki', 'model' => 'Access 125', 'year' => 2022, 'price_day' => 450.00, 'price_hour' => 45.00, 'image_source' => 'scooter.png'],
        ['make' => 'Kawasaki', 'model' => 'Ninja 300', 'year' => 2021, 'price_day' => 2200.00, 'price_hour' => 200.00, 'image_source' => 'sports_bike.png'],
        ['make' => 'TVS', 'model' => 'Apache RTR 160', 'year' => 2022, 'price_day' => 600.00, 'price_hour' => 60.00, 'image_source' => 'sports_bike.png'],
        ['make' => 'Vespa', 'model' => 'SXL 150', 'year' => 2023, 'price_day' => 700.00, 'price_hour' => 70.00, 'image_source' => 'scooter.png'],
        ['make' => 'Ducati', 'model' => 'Scrambler Icon', 'year' => 2020, 'price_day' => 5500.00, 'price_hour' => 500.00, 'image_source' => 'cruiser.png'],
    ];

    $cars = [
        ['make' => 'Toyota', 'model' => 'Corolla', 'year' => 2021, 'price_day' => 2500.00, 'price_hour' => 200.00, 'image_source' => 'sedan.png'],
        ['make' => 'Honda', 'model' => 'Civic', 'year' => 2022, 'price_day' => 2800.00, 'price_hour' => 250.00, 'image_source' => 'sedan.png'],
        ['make' => 'Hyundai', 'model' => 'Creta', 'year' => 2022, 'price_day' => 1500.00, 'price_hour' => 120.00, 'image_source' => 'suv.png'],
        ['make' => 'Maruti Suzuki', 'model' => 'Swift', 'year' => 2023, 'price_day' => 1200.00, 'price_hour' => 100.00, 'image_source' => 'sedan.png'],
        ['make' => 'Ford', 'model' => 'Mustang GT', 'year' => 2020, 'price_day' => 12000.00, 'price_hour' => 1000.00, 'image_source' => 'sports_car.png'],
        ['make' => 'Tesla', 'model' => 'Model Y', 'year' => 2022, 'price_day' => 8000.00, 'price_hour' => 700.00, 'image_source' => 'suv.png'],
        ['make' => 'BMW', 'model' => '3 Series', 'year' => 2021, 'price_day' => 9000.00, 'price_hour' => 800.00, 'image_source' => 'sedan.png'],
        ['make' => 'Audi', 'model' => 'Q5', 'year' => 2022, 'price_day' => 9500.00, 'price_hour' => 850.00, 'image_source' => 'suv.png'],
        ['make' => 'Mercedes-Benz', 'model' => 'C-Class', 'year' => 2022, 'price_day' => 10000.00, 'price_hour' => 900.00, 'image_source' => 'sedan.png'],
        ['make' => 'Chevrolet', 'model' => 'Camaro', 'year' => 2020, 'price_day' => 12000.00, 'price_hour' => 1000.00, 'image_source' => 'sports_car.png'],
    ];

    // Ensure uploads and uploads/vehicles directory structure exists
    $vehiclesUploadDir = $absoluteUploadDir . '/vehicles';
    if (!is_dir($vehiclesUploadDir)) {
        mkdir($vehiclesUploadDir, 0755, true);
    }

    // Override base URL to https://bike.ggnproperty.com as requested
    $baseUrl = 'https://bike.ggnproperty.com';
    $seedSourcesDir = __DIR__ . '/../storage/seed_sources';

    // 5. Seed Bikes with Real Images in structured 'vehicles' folder
    echo "Seeding 10 bikes with real images...\n";
    $bikeCount = 0;
    foreach ($bikes as $index => $b) {
        $vehicleId = Uuid::v4();
        $imageId = Uuid::v4();

        // Path of source image and destination upload path
        $sourceFilePath = $seedSourcesDir . '/' . $b['image_source'];
        if (!is_file($sourceFilePath)) {
            throw new Exception("Source seed image not found: {$sourceFilePath}");
        }

        $fileName = $imageId . '.png';
        $relativeFilePath = 'uploads/vehicles/' . $fileName;
        $destPath = $vehiclesUploadDir . '/' . $fileName;

        // Copy real image file
        copy($sourceFilePath, $destPath);
        $fileSize = filesize($destPath);

        // Record image upload in database
        $imgStmt = $pdo->prepare("
            INSERT INTO images (id, file_path, file_size, mime_type, uploaded_by, created_at)
            VALUES (:id, :file_path, :file_size, 'image/png', :uploaded_by, CURRENT_TIMESTAMP)
        ");
        $imgStmt->execute([
            'id' => $imageId,
            'file_path' => $relativeFilePath,
            'file_size' => $fileSize,
            'uploaded_by' => $userId
        ]);

        $licensePlate = sprintf('MH-12-BK-%04d', 1000 + $index);
        $imagesJson = json_encode([$relativeFilePath]);

        // Insert vehicle
        $vehStmt = $pdo->prepare("
            INSERT INTO vehicles (id, owner_id, make, model, year, license_plate, price_per_day, price_per_hour, status, images, created_at, updated_at)
            VALUES (:id, :owner_id, :make, :model, :year, :license_plate, :price_per_day, :price_per_hour, 'approved', :images, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $vehStmt->execute([
            'id' => $vehicleId,
            'owner_id' => $userId,
            'make' => $b['make'],
            'model' => $b['model'],
            'year' => $b['year'],
            'license_plate' => $licensePlate,
            'price_per_day' => $b['price_day'],
            'price_per_hour' => $b['price_hour'],
            'images' => $imagesJson
        ]);
        $bikeCount++;
    }
    echo "Successfully seeded {$bikeCount} bikes with real images inside vehicles/ subfolder.\n";

    // 6. Seed Cars with Real Images in structured 'vehicles' folder
    echo "Seeding 10 cars with real images...\n";
    $carCount = 0;
    foreach ($cars as $index => $c) {
        $vehicleId = Uuid::v4();
        $imageId = Uuid::v4();

        // Path of source image and destination upload path
        $sourceFilePath = $seedSourcesDir . '/' . $c['image_source'];
        if (!is_file($sourceFilePath)) {
            throw new Exception("Source seed image not found: {$sourceFilePath}");
        }

        $fileName = $imageId . '.png';
        $relativeFilePath = 'uploads/vehicles/' . $fileName;
        $destPath = $vehiclesUploadDir . '/' . $fileName;

        // Copy real image file
        copy($sourceFilePath, $destPath);
        $fileSize = filesize($destPath);

        // Record image upload in database
        $imgStmt = $pdo->prepare("
            INSERT INTO images (id, file_path, file_size, mime_type, uploaded_by, created_at)
            VALUES (:id, :file_path, :file_size, 'image/png', :uploaded_by, CURRENT_TIMESTAMP)
        ");
        $imgStmt->execute([
            'id' => $imageId,
            'file_path' => $relativeFilePath,
            'file_size' => $fileSize,
            'uploaded_by' => $userId
        ]);

        $licensePlate = sprintf('MH-12-CR-%04d', 1000 + $index);
        $imagesJson = json_encode([$relativeFilePath]);

        // Insert vehicle
        $vehStmt = $pdo->prepare("
            INSERT INTO vehicles (id, owner_id, make, model, year, license_plate, price_per_day, price_per_hour, status, images, created_at, updated_at)
            VALUES (:id, :owner_id, :make, :model, :year, :license_plate, :price_per_day, :price_per_hour, 'approved', :images, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $vehStmt->execute([
            'id' => $vehicleId,
            'owner_id' => $userId,
            'make' => $c['make'],
            'model' => $c['model'],
            'year' => $c['year'],
            'license_plate' => $licensePlate,
            'price_per_day' => $c['price_day'],
            'price_per_hour' => $c['price_hour'],
            'images' => $imagesJson
        ]);
        $carCount++;
    }
    echo "Successfully seeded {$carCount} cars with real images inside vehicles/ subfolder.\n";
    echo "\nAll vehicles seeded successfully with real images inside uploads/vehicles/ and base URL https://bike.ggnproperty.com!\n";

} catch (Throwable $e) {
    echo "\nFATAL ERROR: Seeding failed!\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    exit(1);
}
