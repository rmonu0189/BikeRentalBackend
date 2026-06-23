<?php

declare(strict_types=1);

namespace App\Features\Kyc\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Exceptions\ValidationException;
use App\Core\Exceptions\HttpException;
use App\Features\Authentication\Middleware\AuthMiddleware;
use App\Features\Authentication\Repositories\UserRepository;
use App\Features\Kyc\Repositories\KycRepository;

final class KycController
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    /**
     * Submit KYC documents (Address proof and Identity proof).
     */
    public function submit(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $userId = (string) $claims['sub'];

        // Prevent submission if user already has a pending request
        $activeSubmission = KycRepository::findActiveSubmissionByUserId($userId);
        if ($activeSubmission !== null && $activeSubmission['status'] === 'pending') {
            throw new HttpException('A KYC submission is already pending review.', 400);
        }

        // Validate file uploads
        if (!isset($_FILES['address_proof']) || !isset($_FILES['identity_proof'])) {
            throw new ValidationException('Missing required documents.', [
                'documents' => 'Both address_proof and identity_proof files must be uploaded.'
            ]);
        }

        $addressProof = $_FILES['address_proof'];
        $identityProof = $_FILES['identity_proof'];
        $addressDetails = $request->post('address_details');

        // Validate each file
        $addressPath = $this->validateAndSaveFile($addressProof, 'address_proof');
        $identityPath = $this->validateAndSaveFile($identityProof, 'identity_proof');

        // Save to database
        $submissionId = Uuid::v4();
        KycRepository::insertSubmission(
            $submissionId,
            $userId,
            $addressPath,
            $identityPath,
            $addressDetails
        );

        // Update user status to pending
        KycRepository::updateUserKycStatus($userId, 'pending');

        Response::json([
            'message' => 'KYC documents submitted successfully.',
            'submission_id' => $submissionId,
            'status' => 'pending'
        ], 201);
    }

    /**
     * Get the current user's KYC status and latest submission details.
     */
    public function status(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $userId = (string) $claims['sub'];
        $user = UserRepository::findById($userId);
        if ($user === null) {
            throw new HttpException('User not found.', 404);
        }

        $lastSubmission = KycRepository::findActiveSubmissionByUserId($userId);

        Response::json([
            'kyc_status' => $user['kyc_status'],
            'last_submission' => $lastSubmission
        ]);
    }

    /**
     * List all pending KYC submissions for Admin/Staff.
     */
    public function listPending(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $submissions = KycRepository::findPendingSubmissions();
        Response::json([
            'submissions' => $submissions
        ]);
    }

    /**
     * Review a pending KYC submission (Approve or Reject).
     */
    public function review(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $adminId = (string) $claims['sub'];
        $data = $request->json();

        $submissionId = (string) ($data['submission_id'] ?? '');
        $status = (string) ($data['status'] ?? '');
        $rejectionReason = isset($data['rejection_reason']) ? (string) $data['rejection_reason'] : null;

        if ($submissionId === '' || !in_array($status, ['approved', 'rejected'], true)) {
            throw new ValidationException('Invalid review parameters.', [
                'submission_id' => 'Required',
                'status' => 'Must be approved or rejected'
            ]);
        }

        if ($status === 'rejected' && ($rejectionReason === null || trim($rejectionReason) === '')) {
            throw new ValidationException('Rejection reason required.', [
                'rejection_reason' => 'A reason must be provided when rejecting KYC.'
            ]);
        }

        $submission = KycRepository::findById($submissionId);
        if ($submission === null) {
            throw new HttpException('KYC submission not found.', 404);
        }

        if ($submission['status'] !== 'pending') {
            throw new HttpException('This submission has already been reviewed.', 400);
        }

        // Update submission status
        KycRepository::updateSubmissionStatus($submissionId, $status, $rejectionReason, $adminId);

        // Map status to user table
        $userStatus = $status === 'approved' ? 'verified' : 'rejected';
        KycRepository::updateUserKycStatus($submission['user_id'], $userStatus);

        Response::json([
            'message' => sprintf('KYC submission has been %s.', $status),
            'submission_id' => $submissionId,
            'user_id' => $submission['user_id'],
            'kyc_status' => $userStatus
        ]);
    }

    /**
     * Validates and saves an uploaded file, returning its relative path.
     */
    private function validateAndSaveFile(array $file, string $fieldKey): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('File upload failed.', [
                $fieldKey => 'Upload error code: ' . $file['error']
            ]);
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new ValidationException('File size limit exceeded.', [
                $fieldKey => 'File must be under 5MB.'
            ]);
        }

        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException('Unsupported file format.', [
                $fieldKey => 'Allowed file formats: ' . implode(', ', self::ALLOWED_EXTENSIONS)
            ]);
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new HttpException('Internal server error during file validation.', 500);
        }
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new ValidationException('Unsupported file type.', [
                $fieldKey => 'Allowed file types: PDF, JPEG, PNG.'
            ]);
        }

        // Setup upload directory
        $uploadDir = __DIR__ . '/../../../../storage/uploads/kyc';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new HttpException('Failed to create upload directory.', 500);
            }
        }

        // Generate a random, safe filename using UUID v4
        $filename = Uuid::v4() . '.' . $ext;
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new HttpException('Failed to save uploaded file.', 500);
        }

        return 'uploads/kyc/' . $filename;
    }
}
