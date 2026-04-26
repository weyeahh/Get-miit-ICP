<?php

declare(strict_types=1);

namespace Miit\Api;

use Miit\Exception\MiitException;
use Miit\Exception\UpstreamException;
use Miit\Support\DetailSanitizer;
use Miit\Config\AppConfig;

final class MiitClient
{
    private const BASE_URL = 'https://hlwicpfwc.miit.gov.cn/icpproject_query/api/';
    private const SERVICE_HOST = 'hlwicpfwc.miit.gov.cn';
    private const DEFAULT_ORIGIN = 'https://beian.miit.gov.cn';
    private const DEFAULT_REFERER = 'https://beian.miit.gov.cn/';
    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0';
    private const DEFAULT_ACCEPT = 'application/json, text/plain, */*';
    private const DEFAULT_LANGUAGE = 'zh-CN,zh-HK;q=0.9,zh;q=0.8,en-US;q=0.7,en;q=0.6';
    private const DEFAULT_TIMEOUT = 15;

    private string $cookieFile;

    /** @var array<string, string> */
    private array $headers;
    private AppConfig $config;

    public function __construct(private readonly int $timeout = self::DEFAULT_TIMEOUT)
    {
        $this->config = new AppConfig();
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'miit_cookie_');
        if ($this->cookieFile === false) {
            throw new MiitException('failed to create temporary cookie file');
        }

        $this->headers = [
            'Host' => self::SERVICE_HOST,
            'Origin' => self::DEFAULT_ORIGIN,
            'Referer' => self::DEFAULT_REFERER,
            'User-Agent' => self::DEFAULT_USER_AGENT,
            'Accept' => self::DEFAULT_ACCEPT,
            'Accept-Language' => self::DEFAULT_LANGUAGE,
            'Connection' => 'keep-alive',
        ];
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function setHeader(string $key, string $value): void
    {
        if ($value === '') {
            unset($this->headers[$key]);
            return;
        }

        $this->headers[$key] = $value;
    }

    public function setToken(string $token): void
    {
        $this->setHeader('Token', $token);
    }

    public function setSign(string $sign): void
    {
        $this->setHeader('Sign', $sign);
    }

    public function setUuid(string $uuid): void
    {
        $this->setHeader('Uuid', $uuid);
    }

    /** @return array<string, mixed> */
    public function postFormJson(string $path, array $form): array
    {
        $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $response = $this->request('POST', $path, $body, 'application/x-www-form-urlencoded');
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new UpstreamException('failed to decode JSON response', 'upstream query failed');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function postJson(string $path, array $payload): array
    {
        $response = $this->postJsonRaw($path, $payload);
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new UpstreamException('failed to decode JSON response', 'upstream query failed');
        }

        return $decoded;
    }

    public function postJsonRaw(string $path, array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new MiitException('failed to encode JSON payload');
        }

        return $this->request('POST', $path, $body, 'application/json');
    }

    private function resolveUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return self::BASE_URL . ltrim($path, '/');
    }

    private function request(string $method, string $path, string $body, string $contentType): string
    {
        $ch = curl_init($this->resolveUrl($path));
        if ($ch === false) {
            throw new MiitException('failed to initialize curl');
        }

        $headers = [];
        foreach ($this->headers as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
        $headers[] = 'Content-Type: ' . $contentType;

        try {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_COOKIEFILE => $this->cookieFile,
                CURLOPT_COOKIEJAR => $this->cookieFile,
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }

        if ($errno !== 0) {
            throw new UpstreamException('request failed: ' . $error, 'upstream query failed');
        }

        if (!is_string($response)) {
            throw new UpstreamException('request failed: empty response', 'upstream query failed');
        }

        if ($statusCode !== 200) {
            $detail = DetailSanitizer::truncate('request failed: status=' . $statusCode . ' body=' . trim($response), $this->config);
            throw new UpstreamException($detail, 'upstream query failed');
        }

        return $response;
    }
}
