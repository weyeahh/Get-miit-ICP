<?php

declare(strict_types=1);

namespace Miit\Api;

use Miit\Exception\MiitException;

final class AuthApi
{
    private const AUTH_SECRET = 'testtest';

    public function __construct(private readonly MiitClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function auth(int $timestamp): array
    {
        $response = $this->client->postFormJson('auth', [
            'authKey' => self::buildAuthKey($timestamp),
            'timeStamp' => (string) $timestamp,
        ]);

        if (($response['code'] ?? 0) !== 200 || ($response['success'] ?? false) !== true) {
            throw new MiitException(sprintf(
                'auth request rejected: code=%s msg=%s',
                (string) ($response['code'] ?? ''),
                (string) ($response['msg'] ?? '')
            ));
        }

        $business = (string) (($response['params']['bussiness'] ?? ''));
        $this->client->setToken($business);

        return $response;
    }

    public static function buildAuthKey(int $timestamp): string
    {
        return md5(self::AUTH_SECRET . $timestamp);
    }
}
