<?php

declare(strict_types=1);

namespace App\Core;

use App\Features\Authentication\Controllers\AuthController;
use App\Features\Kyc\Controllers\KycController;

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

        $router->dispatch($request);
    }
}
