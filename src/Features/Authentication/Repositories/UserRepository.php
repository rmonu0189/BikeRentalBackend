<?php

declare(strict_types=1);

namespace App\Features\Authentication\Repositories;

use App\Core\Database;
use PDO;

final class UserRepository
{
    public const DEFAULT_ROLE = 'user';
    public const DEFAULT_COUNTRY_CODE = '+91';

    /**
     * @return null if no user; true/false for is_active when row exists
     */
    public static function findActiveFlag(string $id): ?bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT is_active FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $v = $stmt->fetchColumn();

        if ($v === false) {
            return null;
        }

        return (bool) (int) $v;
    }

    /**
     * Find a user by ID.
     */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, country_code, email, full_name, is_active, role, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'phone' => (string) $row['phone'],
            'country_code' => (string) ($row['country_code'] ?? self::DEFAULT_COUNTRY_CODE),
            'email' => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
            'full_name' => $row['full_name'] !== null && $row['full_name'] !== '' ? (string) $row['full_name'] : null,
            'is_active' => (bool) (int) $row['is_active'],
            'role' => (string) ($row['role'] ?? self::DEFAULT_ROLE),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Find user auth details by phone and country code.
     */
    public static function findAuthByPhone(string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, is_active, role FROM users WHERE phone = :phone AND country_code = :country_code LIMIT 1'
        );
        $stmt->execute(['phone' => $phone, 'country_code' => $countryCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'is_active' => (bool) (int) $row['is_active'],
            'role' => isset($row['role']) && $row['role'] !== null && $row['role'] !== '' ? (string) $row['role'] : self::DEFAULT_ROLE,
        ];
    }

    public static function phoneTaken(string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE phone = :phone AND country_code = :country_code LIMIT 1');
        $stmt->execute(['phone' => $phone, 'country_code' => $countryCode]);

        return (bool) $stmt->fetchColumn();
    }

    public static function emailTaken(string $email): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return (bool) $stmt->fetchColumn();
    }

    public static function emailTakenByOtherUser(string $email, string $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $stmt->execute([
            'email' => $email,
            'id' => $id,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public static function insert(
        string $id,
        string $phone,
        ?string $countryCode,
        ?string $email,
        ?string $fullName,
        bool $isActive,
        string $role = self::DEFAULT_ROLE
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (id, phone, country_code, email, full_name, is_active, role)
             VALUES (:id, :phone, :country_code, :email, :full_name, :is_active, :role)'
        );
        $stmt->execute([
            'id' => $id,
            'phone' => $phone,
            'country_code' => $countryCode !== null && trim($countryCode) !== '' ? trim($countryCode) : self::DEFAULT_COUNTRY_CODE,
            'email' => $email,
            'full_name' => $fullName,
            'is_active' => $isActive ? 1 : 0,
            'role' => $role !== '' ? $role : self::DEFAULT_ROLE,
        ]);
    }

    public static function updateFullName(string $id, ?string $fullName): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET full_name = :full_name WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'full_name' => $fullName,
        ]);
    }

    public static function updateEmail(string $id, ?string $email): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET email = :email WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'email' => $email,
        ]);
    }

    /** @return array{phone:string,country_code:string,email:?string}|null */
    public static function findContactById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT phone, country_code, email FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'phone' => (string) ($row['phone'] ?? ''),
            'country_code' => isset($row['country_code']) && $row['country_code'] !== null && $row['country_code'] !== ''
                ? (string) $row['country_code']
                : self::DEFAULT_COUNTRY_CODE,
            'email' => isset($row['email']) && $row['email'] !== '' ? (string) $row['email'] : null,
        ];
    }

    public static function deactivateAndArchiveIdentity(
        string $id,
        string $archivedPhone,
        ?string $originalPhone,
        ?string $originalEmail,
        string $reasonCode,
        ?string $reasonText
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET
                is_active = 0,
                phone = :archived_phone,
                email = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'archived_phone' => $archivedPhone,
        ]);
    }
}
