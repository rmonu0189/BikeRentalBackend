<?php

declare(strict_types=1);

namespace App\Features\Images\Repositories;

use App\Core\Database;
use PDO;

final class ImageRepository
{
    /**
     * Insert a new image record.
     */
    public static function insert(
        string $id,
        string $filePath,
        int $fileSize,
        string $mimeType,
        string $uploadedBy
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO images (id, file_path, file_size, mime_type, uploaded_by, created_at)
             VALUES (:id, :file_path, :file_size, :mime_type, :uploaded_by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'id' => $id,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /**
     * Find an image by its UUID.
     */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, file_path, file_size, mime_type, uploaded_by, created_at FROM images WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Delete an image record by UUID.
     */
    public static function delete(string $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM images WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Find if any vehicle references this image ID/URL in its images JSON array.
     */
    public static function findReferencingVehicle(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, owner_id, status FROM vehicles WHERE images LIKE :pattern LIMIT 1'
        );
        $stmt->execute(['pattern' => '%' . $id . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
