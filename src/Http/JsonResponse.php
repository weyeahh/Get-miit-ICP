<?php

declare(strict_types=1);

namespace Miit\Http;

final class JsonResponse
{
    public static function send(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
