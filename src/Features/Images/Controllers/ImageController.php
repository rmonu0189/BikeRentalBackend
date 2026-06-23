<?php

declare(strict_types=1);

namespace App\Features\Images\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Exceptions\ValidationException;
use App\Core\Exceptions\HttpException;
use App\Features\Authentication\Middleware\AuthMiddleware;
use App\Features\Images\Repositories\ImageRepository;

final class ImageController
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
    ];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB per file

    private function isAdmin(array $claims): bool
    {
        $role = (string) ($claims['role'] ?? '');
        return in_array($role, ['admin', 'staff', 'manager'], true);
    }

    /**
     * Upload a single image file.
     */
    public function upload(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException('Missing required image file.', [
                'image' => 'You must upload an image.'
            ]);
        }

        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('Image upload failed.', [
                'image' => 'Upload error code: ' . $file['error']
            ]);
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new ValidationException('File size limit exceeded.', [
                'image' => 'File must be under 5MB.'
            ]);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException('Unsupported file format.', [
                'image' => 'File format not supported. Allowed formats: ' . implode(', ', self::ALLOWED_EXTENSIONS)
            ]);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new HttpException('Internal server error during file validation.', 500);
        }
        $mimeType = finfo_file($finfo, $file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new ValidationException('Unsupported file type.', [
                'image' => 'File must be a valid JPEG or PNG image.'
            ]);
        }

        $userId = (string) $claims['sub'];
        $uploadDir = __DIR__ . '/../../../../storage/uploads';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new HttpException('Failed to create upload directory.', 500);
            }
        }

        $imageId = Uuid::v4();
        $filename = $imageId . '.' . $ext;
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new HttpException('Failed to save uploaded file.', 500);
        }

        $relativeDir = 'uploads/' . $filename;

        // Save record to database
        try {
            ImageRepository::insert($imageId, $relativeDir, $file['size'], $mimeType, $userId);
        } catch (\Throwable $e) {
            if (is_file($destination)) {
                @unlink($destination);
            }
            throw $e;
        }

        // Build secure URL to return to the caller
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $baseUrl = $protocol . '://' . $host;

        Response::json([
            'message' => 'Image uploaded successfully.',
            'id' => $imageId,
            'url' => $baseUrl . '/v1/images?id=' . $imageId
        ], 201);
    }

    /**
     * Retrieve and stream an image securely.
     */
    public function getSecure(Request $request): void
    {
        // Bearer token must be authenticated
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $id = $request->query('id');
        if ($id === null || trim($id) === '') {
            throw new HttpException('Image ID is required.', 400);
        }

        $image = ImageRepository::findById(trim($id));
        if ($image === null) {
            throw new HttpException('Image not found.', 404);
        }

        // Authorization Checks
        $isOwner = ((string) $claims['sub'] === $image['uploaded_by']);
        $isAdmin = $this->isAdmin($claims);
        $allowed = $isOwner || $isAdmin;

        if (!$allowed) {
            // Find referencing vehicle in vehicles table
            $vehicle = ImageRepository::findReferencingVehicle($image['id']);
            if ($vehicle !== null) {
                // If the vehicle is approved, anyone authenticated can view it
                if ($vehicle['status'] === 'approved') {
                    $allowed = true;
                } else {
                    // If vehicle is pending/rejected, only the vehicle owner (or admin/uploader) can view it
                    $isVehicleOwner = ((string) $claims['sub'] === $vehicle['owner_id']);
                    if ($isVehicleOwner) {
                        $allowed = true;
                    }
                }
            }
        }

        if (!$allowed) {
            throw new HttpException('Forbidden. You do not have permission to access this image.', 403);
        }

        // Stream physical file
        $storageDir = __DIR__ . '/../../../../storage';
        $fullPath = $storageDir . '/' . $image['file_path'];

        if (!is_file($fullPath)) {
            throw new HttpException('Physical image file not found on disk.', 404);
        }

        // Clean output buffer to ensure clean file transfer
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $image['mime_type']);
        header('Content-Length: ' . $image['file_size']);
        header('Cache-Control: private, max-age=86400');
        readfile($fullPath);
        exit;
    }

    private function cleanupPhysicalFiles(array $uploadedImages): void
    {
        $storageDir = __DIR__ . '/../../../../storage';
        foreach ($uploadedImages as $img) {
            $fullPath = $storageDir . '/' . $img['path'];
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
}
