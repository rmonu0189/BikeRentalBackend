<?php

declare(strict_types=1);

namespace App\Features\Vehicles\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Env;
use App\Core\Exceptions\ValidationException;
use App\Core\Exceptions\HttpException;
use App\Features\Authentication\Middleware\AuthMiddleware;
use App\Features\Authentication\Repositories\UserRepository;
use App\Features\Vehicles\Repositories\VehicleRepository;

final class VehicleController
{
    private function isAdmin(array $claims): bool
    {
        $role = (string) ($claims['role'] ?? '');
        return in_array($role, ['admin', 'staff', 'manager'], true);
    }

    /**
     * Get a specific vehicle or list vehicles based on user role.
     */
    public function getOrList(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $id = $request->query('id');

        if ($id !== null && trim($id) !== '') {
            // Retrieve specific vehicle
            $vehicle = VehicleRepository::findById($id);
            if ($vehicle === null) {
                throw new HttpException('Vehicle not found.', 404);
            }

            // Authorization check:
            // Approved vehicles are visible to anyone.
            // Pending/Rejected vehicles are only visible to the owner or admins.
            if ($vehicle['status'] !== 'approved') {
                $isOwner = ((string) $claims['sub'] === $vehicle['owner_id']);
                $isAdmin = $this->isAdmin($claims);
                if (!$isOwner && !$isAdmin) {
                    throw new HttpException('Forbidden. You do not have permission to view this vehicle.', 403);
                }
            }

            Response::json($vehicle);
            return;
        }

        // List vehicles based on role
        $isAdmin = $this->isAdmin($claims);
        $role = (string) ($claims['role'] ?? '');
        $userId = (string) $claims['sub'];

        if ($isAdmin) {
            // Admins can see all vehicles
            $vehicles = VehicleRepository::findAll();
        } elseif ($role === 'renter') {
            // Renters see approved vehicles + their own vehicles
            $approved = VehicleRepository::findAllApproved();
            $owned = VehicleRepository::findByOwner($userId);
            
            // Merge and de-duplicate by ID
            $merged = [];
            foreach ($approved as $v) {
                $merged[$v['id']] = $v;
            }
            foreach ($owned as $v) {
                // Keep the detailed keys from owned vehicles (like rejection_reason)
                $merged[$v['id']] = $v;
            }
            $vehicles = array_values($merged);
        } else {
            // Normal users ('user') only see approved vehicles
            $vehicles = VehicleRepository::findAllApproved();
        }

        Response::json(['vehicles' => $vehicles]);
    }

    /**
     * Add a new vehicle. Only Renters and Admins can add vehicles.
     */
    public function add(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $isAdmin = $this->isAdmin($claims);
        $role = (string) ($claims['role'] ?? '');

        if ($role !== 'renter' && !$isAdmin) {
            throw new HttpException('Forbidden. Only renters and administrators can add vehicles.', 403);
        }

        $data = $request->json();

        // Validate required fields
        $make = isset($data['make']) ? trim((string) $data['make']) : '';
        $model = isset($data['model']) ? trim((string) $data['model']) : '';
        $yearInput = isset($data['year']) ? $data['year'] : null;
        $licensePlate = isset($data['license_plate']) ? trim((string) $data['license_plate']) : '';
        $pricePerDayInput = isset($data['price_per_day']) ? $data['price_per_day'] : null;
        $pricePerHourInput = isset($data['price_per_hour']) ? $data['price_per_hour'] : null;

        $errors = [];
        if ($make === '') {
            $errors['make'] = 'Make is required.';
        }
        if ($model === '') {
            $errors['model'] = 'Model is required.';
        }
        if ($yearInput === null || !is_numeric($yearInput) || (int) $yearInput < 1900 || (int) $yearInput > 2100) {
            $errors['year'] = 'Valid year is required (between 1900 and 2100).';
        }
        if ($licensePlate === '') {
            $errors['license_plate'] = 'License plate is required.';
        }
        if ($pricePerDayInput === null || !is_numeric($pricePerDayInput) || (float) $pricePerDayInput < 0) {
            $errors['price_per_day'] = 'Price per day must be a non-negative number.';
        }
        if ($pricePerHourInput === null || !is_numeric($pricePerHourInput) || (float) $pricePerHourInput < 0) {
            $errors['price_per_hour'] = 'Price per hour must be a non-negative number.';
        }

        if (!empty($errors)) {
            throw new ValidationException('Validation failed.', $errors);
        }

        // Determine owner ID: Renters add for themselves, admins can specify owner_id or default to themselves.
        $ownerId = $userId = (string) $claims['sub'];
        if ($isAdmin && isset($data['owner_id']) && trim((string) $data['owner_id']) !== '') {
            $ownerId = trim((string) $data['owner_id']);
            // Verify owner exists and is active
            if (UserRepository::findById($ownerId) === null) {
                throw new HttpException('Target owner user not found.', 404);
            }
        }

        // Determine verification status
        $verificationRequired = Env::get('VEHICLE_VERIFICATION_REQUIRED', 'true') === 'true';
        $status = $verificationRequired ? 'pending' : 'approved';

        $vehicleId = Uuid::v4();

        VehicleRepository::insert(
            $vehicleId,
            $ownerId,
            $make,
            $model,
            (int) $yearInput,
            $licensePlate,
            (float) $pricePerDayInput,
            (float) $pricePerHourInput,
            $status
        );

        Response::json([
            'message' => 'Vehicle added successfully.',
            'vehicle_id' => $vehicleId,
            'status' => $status
        ], 201);
    }

    /**
     * Update an existing vehicle. Owners (renters) can update their own. Admins can update any.
     */
    public function update(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $data = $request->json();
        $id = isset($data['id']) ? trim((string) $data['id']) : '';

        if ($id === '') {
            throw new ValidationException('Validation failed.', ['id' => 'Vehicle ID is required.']);
        }

        $vehicle = VehicleRepository::findById($id);
        if ($vehicle === null) {
            throw new HttpException('Vehicle not found.', 404);
        }

        // Enforce owner / admin roles
        $isOwner = ((string) $claims['sub'] === $vehicle['owner_id']);
        $isAdmin = $this->isAdmin($claims);

        if (!$isOwner && !$isAdmin) {
            throw new HttpException('Forbidden. You do not have permission to update this vehicle.', 403);
        }

        // Validate updated fields
        $make = isset($data['make']) ? trim((string) $data['make']) : $vehicle['make'];
        $model = isset($data['model']) ? trim((string) $data['model']) : $vehicle['model'];
        $yearInput = isset($data['year']) ? $data['year'] : $vehicle['year'];
        $licensePlate = isset($data['license_plate']) ? trim((string) $data['license_plate']) : $vehicle['license_plate'];
        $pricePerDayInput = isset($data['price_per_day']) ? $data['price_per_day'] : $vehicle['price_per_day'];
        $pricePerHourInput = isset($data['price_per_hour']) ? $data['price_per_hour'] : $vehicle['price_per_hour'];

        $errors = [];
        if ($make === '') {
            $errors['make'] = 'Make cannot be empty.';
        }
        if ($model === '') {
            $errors['model'] = 'Model cannot be empty.';
        }
        if (!is_numeric($yearInput) || (int) $yearInput < 1900 || (int) $yearInput > 2100) {
            $errors['year'] = 'Valid year is required (between 1900 and 2100).';
        }
        if ($licensePlate === '') {
            $errors['license_plate'] = 'License plate cannot be empty.';
        }
        if (!is_numeric($pricePerDayInput) || (float) $pricePerDayInput < 0) {
            $errors['price_per_day'] = 'Price per day must be a non-negative number.';
        }
        if (!is_numeric($pricePerHourInput) || (float) $pricePerHourInput < 0) {
            $errors['price_per_hour'] = 'Price per hour must be a non-negative number.';
        }

        if (!empty($errors)) {
            throw new ValidationException('Validation failed.', $errors);
        }

        // Determine status
        // If verification is required, a renter's updates reset the status to pending.
        // If update is done by admin, status is preserved as is unless explicitly specified.
        $verificationRequired = Env::get('VEHICLE_VERIFICATION_REQUIRED', 'true') === 'true';
        
        if ($isAdmin) {
            $status = isset($data['status']) ? trim((string) $data['status']) : $vehicle['status'];
            if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
                $status = $vehicle['status'];
            }
        } else {
            $status = $verificationRequired ? 'pending' : 'approved';
        }

        VehicleRepository::update(
            $id,
            $make,
            $model,
            (int) $yearInput,
            $licensePlate,
            (float) $pricePerDayInput,
            (float) $pricePerHourInput,
            $status
        );

        Response::json([
            'message' => 'Vehicle updated successfully.',
            'vehicle_id' => $id,
            'status' => $status
        ]);
    }

    /**
     * Delete a vehicle. Owners can delete their own. Admins can delete any.
     */
    public function delete(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $id = $request->query('id');
        if ($id === null || trim($id) === '') {
            throw new ValidationException('Validation failed.', ['id' => 'Vehicle ID is required.']);
        }

        $vehicle = VehicleRepository::findById($id);
        if ($vehicle === null) {
            throw new HttpException('Vehicle not found.', 404);
        }

        $isOwner = ((string) $claims['sub'] === $vehicle['owner_id']);
        $isAdmin = $this->isAdmin($claims);

        if (!$isOwner && !$isAdmin) {
            throw new HttpException('Forbidden. You do not have permission to delete this vehicle.', 403);
        }

        VehicleRepository::delete($id);

        Response::json([
            'message' => 'Vehicle deleted successfully.'
        ]);
    }

    /**
     * List pending vehicles awaiting review (Admin/Staff only).
     */
    public function listPending(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $vehicles = VehicleRepository::findPending();
        Response::json([
            'vehicles' => $vehicles
        ]);
    }

    /**
     * Review a pending vehicle (Admin/Staff only).
     */
    public function review(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $adminId = (string) $claims['sub'];
        $data = $request->json();

        $vehicleId = isset($data['vehicle_id']) ? trim((string) $data['vehicle_id']) : '';
        $status = isset($data['status']) ? trim((string) $data['status']) : '';
        $rejectionReason = isset($data['rejection_reason']) ? trim((string) $data['rejection_reason']) : null;

        if ($vehicleId === '' || !in_array($status, ['approved', 'rejected'], true)) {
            throw new ValidationException('Invalid review parameters.', [
                'vehicle_id' => 'Required',
                'status' => 'Must be approved or rejected'
            ]);
        }

        if ($status === 'rejected' && ($rejectionReason === null || $rejectionReason === '')) {
            throw new ValidationException('Rejection reason required.', [
                'rejection_reason' => 'A reason must be provided when rejecting a vehicle.'
            ]);
        }

        $vehicle = VehicleRepository::findById($vehicleId);
        if ($vehicle === null) {
            throw new HttpException('Vehicle not found.', 404);
        }

        if ($vehicle['status'] !== 'pending') {
            throw new HttpException('This vehicle has already been reviewed.', 400);
        }

        VehicleRepository::updateStatus($vehicleId, $status, $rejectionReason, $adminId);

        Response::json([
            'message' => sprintf('Vehicle has been %s.', $status),
            'vehicle_id' => $vehicleId,
            'status' => $status
        ]);
    }
}
