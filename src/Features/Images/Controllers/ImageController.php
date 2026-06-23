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
     * Upload one or more image files.
     */
    public function upload(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        if (!isset($_FILES['images']) || !is_array($_FILES['images']['name'])) {
            throw new ValidationException('Missing required image files.', [
                'images' => 'You must upload at least 1 image.'
            ]);
        }

        $names = $_FILES['images']['name'];
        $tmpNames = $_FILES['images']['tmp_name'];
        $errors = $_FILES['images']['error'];
        $sizes = $_FILES['images']['size'];

        $validFileCount = 0;
        for ($i = 0; $i < count($names); $i++) {
            if ($errors[$i] !== UPLOAD_ERR_NO_FILE) {
                $validFileCount++;
            }
        }

        if ($validFileCount < 1 || $validFileCount > 5) {
            throw new ValidationException('Invalid number of image files.', [
                'images' => 'You must upload between 1 and 5 files.'
            ]);
        }

        $uploadedImages = [];
        $userId = (string) $claims['sub'];

        $uploadDir = __DIR__ . '/../../../../storage/uploads';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new HttpException('Failed to create upload directory.', 500);
            }
        }

        for ($i = 0; $i < count($names); $i++) {
            if ($errors[$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($errors[$i] !== UPLOAD_ERR_OK) {
                $this->cleanupPhysicalFiles($uploadedImages);
                throw new ValidationException('Image upload failed.', [
                    'images' => 'Upload error code: ' . $errors[$i] . ' for file ' . $names[$i]
                ]);
            }

            if ($sizes[$i] > self::MAX_FILE_SIZE) {
                $this->cleanupPhysicalFiles($uploadedImages);
                throw new ValidationException('File size limit exceeded.', [
                    'images' => 'File ' . $names[$i] . ' must be under 5MB.'
                ]);
            }

            $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $this->cleanupPhysicalFiles($uploadedImages);
                throw new ValidationException('Unsupported file format.', [
                    'images' => 'File ' . $names[$i] . ' format not supported. Allowed formats: ' . implode(', ', self::ALLOWED_EXTENSIONS)
                ]);
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                $this->cleanupPhysicalFiles($uploadedImages);
                throw new HttpException('Internal server error during file validation.', 500);
            }
            $mimeType = finfo_file($finfo, $tmpNames[$i]);

            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                $this->cleanupPhysicalFiles($uploadedImages);
                throw new ValidationException('Unsupported file type.', [
                    'images' => 'File ' . $names[$i] . ' must be a valid JPEG or PNG image.'
                ]);
            }

            $imageId = Uuid::v4();
            $filename = $imageId . '.' . $ext;
            $destination = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($tmpNames[$i], $destination)) {
                $this->cleanupPhysicalFiles($uploadedImages);
                throw new HttpException('Failed to save uploaded file ' . $names[$i], 500);
            }

            $relativeDir = 'uploads/' . $filename;
            $uploadedImages[] = [
                'id' => $imageId,
                'path' => $relativeDir,
                'size' => $sizes[$i],
                'mime' => $mimeType
            ];
        }

        // Save records to database
        try {
            foreach ($uploadedImages as $img) {
                ImageRepository::insert($img['id'], $img['path'], $img['size'], $img['mime'], $userId);
            }
        } catch (\Throwable $e) {
            $this->cleanupPhysicalFiles($uploadedImages);
            throw $e;
        }

        // Build list of secure URLs to return to the caller
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $baseUrl = $protocol . '://' . $host;

        $responseImages = [];
        foreach ($uploadedImages as $img) {
            $responseImages[] = [
                'id' => $img['id'],
                'url' => $baseUrl . '/v1/images?id=' . $img['id']
            ];
        }

        Response::json([
            'message' => 'Images uploaded successfully.',
            'images' => $responseImages
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
