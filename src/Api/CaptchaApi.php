<?php

declare(strict_types=1);

namespace Miit\Api;

use Miit\Exception\MiitException;

final class CaptchaApi
{
    public function __construct(private readonly MiitClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function getCheckImagePoint(string $clientUid): array
    {
        $response = $this->client->postJson('image/getCheckImagePoint', [
            'clientUid' => $clientUid,
        ]);

        if (($response['code'] ?? 0) !== 200 || ($response['success'] ?? false) !== true) {
            throw new MiitException(sprintf(
                'getCheckImagePoint rejected: code=%s msg=%s',
                (string) ($response['code'] ?? ''),
                (string) ($response['msg'] ?? '')
            ));
        }

        return $response;
    }

    /** @return array<string, mixed> */
    public function tryCheckImage(string $key, int $value): array
    {
        return $this->client->postJson('image/checkImage', [
            'key' => $key,
            'value' => (string) $value,
        ]);
    }

    public static function newClientUid(): string
    {
        $raw = random_bytes(16);
        $raw[6] = chr((ord($raw[6]) & 0x0f) | 0x40);
        $raw[8] = chr((ord($raw[8]) & 0x3f) | 0x80);
        $hex = bin2hex($raw);

        return sprintf(
            'point-%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
