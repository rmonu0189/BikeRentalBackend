<?php

declare(strict_types=1);

namespace App\Features\Kyc\Repositories;

use App\Core\Database;
use PDO;

final class KycRepository
{
    /**
     * Insert a new KYC submission.
     */
    public static function insertSubmission(
        string $id,
        string $userId,
        string $addressProofPath,
        string $identityProofPath,
        ?string $addressDetails
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO kyc_submissions (
                id, user_id, address_proof_path, identity_proof_path, address_details, status, created_at, updated_at
             ) VALUES (
                :id, :user_id, :address_proof_path, :identity_proof_path, :address_details, \'pending\', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'address_proof_path' => $addressProofPath,
            'identity_proof_path' => $identityProofPath,
            'address_details' => $addressDetails,
        ]);
    }

    /**
     * Find the most recent active submission for a user.
     */
    public static function findActiveSubmissionByUserId(string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, address_proof_path, identity_proof_path, address_details, status, rejection_reason, reviewed_by, reviewed_at, created_at, updated_at
             FROM kyc_submissions
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $row;
    }

    /**
     * Find a submission by its ID.
     */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, address_proof_path, identity_proof_path, address_details, status, rejection_reason, reviewed_by, reviewed_at, created_at, updated_at
             FROM kyc_submissions
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $row;
    }

    /**
     * Find all pending submissions.
     */
    public static function findPendingSubmissions(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT k.id, k.user_id, k.address_proof_path, k.identity_proof_path, k.address_details, k.status, k.created_at, u.full_name, u.phone, u.email
             FROM kyc_submissions k
             JOIN users u ON k.user_id = u.id
             WHERE k.status = \'pending\'
             ORDER BY k.created_at ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Update KYC submission status.
     */
    public static function updateSubmissionStatus(
        string $id,
        string $status,
        ?string $rejectionReason,
        ?string $adminId
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE kyc_submissions
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

    /**
     * Update user's general KYC status.
     */
    public static function updateUserKycStatus(string $userId, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET kyc_status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'status' => $status,
        ]);
    }
}
