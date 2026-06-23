<?php

declare(strict_types=1);

namespace App\Features\Vehicles\Repositories;

use App\Core\Database;
use PDO;

final class VehicleRepository
{
    /**
     * Insert a new vehicle record.
     */
    public static function insert(
        string $id,
        string $ownerId,
        string $make,
        string $model,
        int $year,
        string $licensePlate,
        float $pricePerDay,
        float $pricePerHour,
        string $status,
        array $images
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO vehicles (
                id, owner_id, make, model, year, license_plate, price_per_day, price_per_hour, status, images, created_at, updated_at
             ) VALUES (
                :id, :owner_id, :make, :model, :year, :license_plate, :price_per_day, :price_per_hour, :status, :images, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'id' => $id,
            'owner_id' => $ownerId,
            'make' => $make,
            'model' => $model,
            'year' => $year,
            'license_plate' => $licensePlate,
            'price_per_day' => $pricePerDay,
            'price_per_hour' => $pricePerHour,
            'status' => $status,
            'images' => json_encode($images),
        ]);
    }

    /**
     * Update an existing vehicle record.
     */
    public static function update(
        string $id,
        string $make,
        string $model,
        int $year,
        string $licensePlate,
        float $pricePerDay,
        float $pricePerHour,
        string $status,
        array $images
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE vehicles
             SET make = :make,
                 model = :model,
                 year = :year,
                 license_plate = :license_plate,
                 price_per_day = :price_per_day,
                 price_per_hour = :price_per_hour,
                 status = :status,
                 images = :images,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'make' => $make,
            'model' => $model,
            'year' => $year,
            'license_plate' => $licensePlate,
            'price_per_day' => $pricePerDay,
            'price_per_hour' => $pricePerHour,
            'status' => $status,
            'images' => json_encode($images),
        ]);
    }

    /**
     * Delete a vehicle.
     */
    public static function delete(string $id): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM vehicles WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Find a vehicle by ID.
     */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, owner_id, make, model, year, license_plate, price_per_day, price_per_hour, status, rejection_reason, reviewed_by, reviewed_at, images, created_at, updated_at
             FROM vehicles
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'owner_id' => (string) $row['owner_id'],
            'make' => (string) $row['make'],
            'model' => (string) $row['model'],
            'year' => (int) $row['year'],
            'license_plate' => (string) $row['license_plate'],
            'price_per_day' => (float) $row['price_per_day'],
            'price_per_hour' => (float) $row['price_per_hour'],
            'status' => (string) $row['status'],
            'rejection_reason' => $row['rejection_reason'] !== null ? (string) $row['rejection_reason'] : null,
            'reviewed_by' => $row['reviewed_by'] !== null ? (string) $row['reviewed_by'] : null,
            'reviewed_at' => $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
            'images' => json_decode($row['images'] ?? '[]', true) ?: [],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Find all pending vehicles for admin review.
     */
    public static function findPending(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT v.id, v.owner_id, v.make, v.model, v.year, v.license_plate, v.price_per_day, v.price_per_hour, v.status, v.images, v.created_at, u.full_name as owner_name, u.phone as owner_phone
             FROM vehicles v
             JOIN users u ON v.owner_id = u.id
             WHERE v.status = \'pending\'
             ORDER BY v.created_at ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    /**
     * Find all approved vehicles.
     */
    public static function findAllApproved(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, owner_id, make, model, year, license_plate, price_per_day, price_per_hour, status, images, created_at
             FROM vehicles
             WHERE status = \'approved\'
             ORDER BY created_at DESC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    /**
     * Find vehicles by owner.
     */
    public static function findByOwner(string $ownerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, owner_id, make, model, year, license_plate, price_per_day, price_per_hour, status, rejection_reason, images, created_at
             FROM vehicles
             WHERE owner_id = :owner_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['owner_id' => $ownerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    /**
     * Find all vehicles (for admins).
     */
    public static function findAll(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT v.id, v.owner_id, v.make, v.model, v.year, v.license_plate, v.price_per_day, v.price_per_hour, v.status, v.images, v.created_at, u.full_name as owner_name
             FROM vehicles v
             LEFT JOIN users u ON v.owner_id = u.id
             ORDER BY v.created_at DESC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    /**
     * Update vehicle verification status.
     */
    public static function updateStatus(
        string $id,
        string $status,
        ?string $rejectionReason,
        ?string $adminId
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE vehicles
             SET status = :status,
                 rejection_reason = :rejection_reason,
                 reviewed_by = :reviewed_by,
                 reviewed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'rejection_reason' => $rejectionReason,
            'reviewed_by' => $adminId,
        ]);
    }
}
