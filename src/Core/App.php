<?php

declare(strict_types=1);

namespace App\Core;

use App\Features\Authentication\Controllers\AuthController;
use App\Features\Kyc\Controllers\KycController;
use App\Features\Vehicles\Controllers\VehicleController;
use App\Features\Images\Controllers\ImageController;

final class App
{
    private const API_PREFIX = '/v1';

    public function run(): void
    {
        SecurityHeaders::apply();

        $router = new Router();
        $request = new Request();

        // CORS preflight
        if ($request->method() === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $auth = new AuthController();
        $kyc = new KycController();
        $vehicles = new VehicleController();
        $images = new ImageController();

        // Auth OTP login endpoints
        $router->add('POST', self::API_PREFIX . '/auth/otp/send', static function (Request $r) use ($auth): void {
            $auth->otpSend($r);
        });

        $router->add('POST', self::API_PREFIX . '/auth/otp/verify', static function (Request $r) use ($auth): void {
            $auth->otpVerify($r);
        });

        $router->add('POST', self::API_PREFIX . '/auth/refresh', static function (Request $r) use ($auth): void {
            $auth->refresh($r);
        });

        $router->add('POST', self::API_PREFIX . '/auth/logout', static function (Request $r) use ($auth): void {
            $auth->logout($r);
        });

        $router->add('GET', self::API_PREFIX . '/auth/me', static function (Request $r) use ($auth): void {
            $auth->me($r);
        });

        $router->add('PATCH', self::API_PREFIX . '/auth/me', static function (Request $r) use ($auth): void {
            $auth->patchMe($r);
        });

        $router->add('POST', self::API_PREFIX . '/auth/delete-account', static function (Request $r) use ($auth): void {
            $auth->deleteAccount($r);
        });

        // User KYC endpoints
        $router->add('POST', self::API_PREFIX . '/kyc/submit', static function (Request $r) use ($kyc): void {
            $kyc->submit($r);
        });

        $router->add('GET', self::API_PREFIX . '/kyc/status', static function (Request $r) use ($kyc): void {
            $kyc->status($r);
        });

        // Admin/Staff KYC review endpoints
        $router->add('GET', self::API_PREFIX . '/admin/kyc/pending', static function (Request $r) use ($kyc): void {
            $kyc->listPending($r);
        });

        $router->add('POST', self::API_PREFIX . '/admin/kyc/review', static function (Request $r) use ($kyc): void {
            $kyc->review($r);
        });

        // Vehicles endpoints
        $router->add('GET', self::API_PREFIX . '/vehicles', static function (Request $r) use ($vehicles): void {
            $vehicles->getOrList($r);
        });

        $router->add('POST', self::API_PREFIX . '/vehicles', static function (Request $r) use ($vehicles): void {
            $vehicles->add($r);
        });

        $router->add('PUT', self::API_PREFIX . '/vehicles', static function (Request $r) use ($vehicles): void {
            $vehicles->update($r);
        });

        $router->add('DELETE', self::API_PREFIX . '/vehicles', static function (Request $r) use ($vehicles): void {
            $vehicles->delete($r);
        });

        // Admin/Staff Vehicles review endpoints
        $router->add('GET', self::API_PREFIX . '/admin/vehicles/pending', static function (Request $r) use ($vehicles): void {
            $vehicles->listPending($r);
        });

        $router->add('POST', self::API_PREFIX . '/admin/vehicles/review', static function (Request $r) use ($vehicles): void {
            $vehicles->review($r);
        });

        // Images endpoints
        $router->add('POST', self::API_PREFIX . '/images/upload', static function (Request $r) use ($images): void {
            $images->upload($r);
        });

        $router->add('GET', self::API_PREFIX . '/images', static function (Request $r) use ($images): void {
            $images->getSecure($r);
        });

        $router->dispatch($request);
    }
}
