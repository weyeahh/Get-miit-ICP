<?php

declare(strict_types=1);

namespace Miit\Http;

final class JsonResponse
{
    public static function send(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{"code":500,"message":"response encoding failed","data":null}';
        }

        echo $json;
        exit;
    }
}
