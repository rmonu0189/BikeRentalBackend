<?php

declare(strict_types=1);

namespace App\Features\Authentication\Controllers;

use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Phone;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Features\Authentication\Middleware\AuthMiddleware;
use App\Features\Authentication\Repositories\UserRepository;
use App\Features\Authentication\Security\LoginLockout;
use App\Features\Authentication\Security\RateLimiter;
use App\Features\Authentication\Services\AuthService;

final class AuthController
{
    private AuthService $auth;
    private LoginLockout $lockout;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->lockout = new LoginLockout();
        $this->rateLimiter = new RateLimiter();
    }

    public function otpSend(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $ip = $request->ip();
        $rlIp = $this->rateLimiter->consume('auth:otp:send:ip:' . $ip, 30, 3600);
        if (!$rlIp['allowed']) {
            throw new HttpException('Too many OTP requests', 429, [
                'retry_after' => $rlIp['retry_after'],
            ]);
        }

        $body = $request->json();
        $phone = (string) ($body['phone'] ?? '');
        $parsed = Phone::parseLocalAndCountryCode($phone);
        if ($parsed !== null) {
            $rlPhone = $this->rateLimiter->consume('auth:otp:send:phone:' . $parsed['country_code'] . ':' . $parsed['phone'], 5, 900);
            if (!$rlPhone['allowed']) {
                throw new HttpException('Too many OTP requests for this phone number', 429, [
                    'retry_after' => $rlPhone['retry_after'],
                ]);
            }
        }

        $ua = $this->resolveUserAgent($request, $body);
        if ($ua !== null) {
            $existing = $body['user_agent'] ?? null;
            if (!is_string($existing) || trim($existing) === '') {
                $body['user_agent'] = $ua;
            }
        }

        $payload = $this->auth->requestOtp($body);
        Response::json($payload);
    }

    public function otpVerify(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $body = $request->json();
        $phone = (string) ($body['phone'] ?? '');
        $parsed = Phone::parseLocalAndCountryCode($phone);
        if ($parsed === null) {
            Response::json([
                'error' => 'Invalid phone number',
                'errors' => ['phone' => 'Provide 10 digits (without country code).'],
            ], 422);
            return;
        }

        $ip = $request->ip();
        $lockKey = $parsed['country_code'] . ':' . $parsed['phone'];
        $locked = $this->lockout->isLocked($lockKey, $ip);
        if ($locked['locked']) {
            throw new HttpException('Too many failed attempts', 429, [
                'retry_after' => $locked['retry_after'],
            ]);
        }

        $rl = $this->rateLimiter->consume('auth:otp:verify:ip:' . $ip, 80, 900);
        if (!$rl['allowed']) {
            throw new HttpException('Too many verification attempts', 429, [
                'retry_after' => $rl['retry_after'],
            ]);
        }

        $ua = $this->resolveUserAgent($request, $body);
        $device = $this->optionalDeviceLabel($body);

        try {
            $payload = $this->auth->verifyOtp($body, $ua, $device);
        } catch (HttpException $e) {
            if ($e->statusCode() === 401) {
                $this->lockout->onFailedAttempt($lockKey, $ip);
            }
            throw $e;
        }

        $this->lockout->onSuccessfulLogin($lockKey, $ip);

        Response::json($payload);
    }

    public function refresh(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $body = $request->json();
        $token = (string) ($body['refresh_token'] ?? '');
        $ua = $this->resolveUserAgent($request, $body);
        $device = $this->optionalDeviceLabel($body);

        $payload = $this->auth->refresh($token, $ua, $device);
        Response::json($payload);
    }

    public function logout(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $body = $request->json();
        $token = (string) ($body['refresh_token'] ?? '');
        $this->auth->logout($token);
        Response::json(['ok' => true]);
    }

    public function me(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $sub = (string) ($claims['sub'] ?? '');
        $user = UserRepository::findById($sub);
        if ($user === null) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Response::json(['user' => $user]);
    }

    public function patchMe(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!array_key_exists('full_name', $body) && !array_key_exists('email', $body)) {
            Response::json(['error' => 'Nothing to update', 'errors' => ['fields' => 'Provide full_name or email.']], 422);
            return;
        }

        if (array_key_exists('full_name', $body)) {
            $raw = $body['full_name'];
            $fullName = null;
            if ($raw !== null && $raw !== '') {
                $s = trim((string) $raw);
                if (strlen($s) > 255) {
                    Response::json(['error' => 'Invalid full name', 'errors' => ['full_name' => 'At most 255 characters.']], 422);
                    return;
                }
                $fullName = $s === '' ? null : $s;
            }
            UserRepository::updateFullName($sub, $fullName);
        }

        if (array_key_exists('email', $body)) {
            $rawEmail = $body['email'];
            $email = null;
            if ($rawEmail !== null && $rawEmail !== '') {
                $s = strtolower(trim((string) $rawEmail));
                if ($s !== '' && !filter_var($s, FILTER_VALIDATE_EMAIL)) {
                    Response::json(['error' => 'Invalid email', 'errors' => ['email' => 'Must be a valid email address.']], 422);
                    return;
                }
                if (strlen($s) > 255) {
                    Response::json(['error' => 'Invalid email', 'errors' => ['email' => 'At most 255 characters.']], 422);
                    return;
                }
                if ($s !== '' && UserRepository::emailTakenByOtherUser($s, $sub)) {
                    Response::json(['error' => 'Email already in use', 'errors' => ['email' => 'This email address is already linked to another account.']], 409);
                    return;
                }
                $email = $s === '' ? null : $s;
            }
            UserRepository::updateEmail($sub, $email);
        }

        $user = UserRepository::findById($sub);
        if ($user === null) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Response::json(['user' => $user]);
    }

    public function deleteAccount(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $payload = $this->auth->deleteAccount($sub, $body);
        Response::json($payload);
    }

    /** @param array<string, mixed> $body */
    private function resolveUserAgent(Request $request, array $body): ?string
    {
        $fromBody = $body['user_agent'] ?? null;
        if (is_string($fromBody) && trim($fromBody) !== '') {
            $s = trim($fromBody);
            return strlen($s) > 512 ? substr($s, 0, 512) : $s;
        }

        $h = $request->header('User-Agent');
        if ($h === null || trim($h) === '') {
            return null;
        }

        return strlen($h) > 512 ? substr($h, 0, 512) : $h;
    }

    /** @param array<string, mixed> $body */
    private function optionalDeviceLabel(array $body): ?string
    {
        $v = $body['device_label'] ?? null;
        if (!is_string($v)) {
            return null;
        }

        $s = trim($v);
        if ($s === '') {
            return null;
        }

        return strlen($s) > 128 ? substr($s, 0, 128) : $s;
    }
}
