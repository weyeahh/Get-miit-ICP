<?php

declare(strict_types=1);

namespace Miit\Service;

use Miit\Api\AuthApi;
use Miit\Api\CaptchaApi;
use Miit\Api\IcpApi;
use Miit\Api\MiitClient;
use Miit\Captcha\CaptchaSolver;
use Miit\Exception\MiitException;
use Miit\Exception\RecordNotFoundException;
use Miit\Exception\UpstreamException;
use Miit\Support\Debug;

final class MiitQueryService
{
    /** @var list<string> */
    private const DETAIL_FIELDS = ['domain', 'unitName', 'mainLicence', 'serviceLicence', 'natureName', 'leaderName', 'updateRecordTime'];

    /** @var array<string, list<string>> */
    private const ID_FIELD_CANDIDATES = [
        'mainId' => ['mainId', 'mainID', 'main_id', 'ids.mainId', 'record.mainId', 'mainInfo.mainId'],
        'domainId' => ['domainId', 'domainID', 'domain_id', 'ids.domainId', 'record.domainId', 'domainInfo.domainId'],
        'serviceId' => ['serviceId', 'serviceID', 'service_id', 'ids.serviceId', 'record.serviceId', 'serviceInfo.serviceId'],
    ];

    /** @var list<string> */
    private const DOMAIN_FIELD_CANDIDATES = ['domain', 'domainName', 'serviceDomain', 'websiteDomain'];

    public function __construct(private readonly int $timeout = 15)
    {
    }

    /** @return array<string, mixed> */
    public function queryDomainDetail(string $domain, bool $debug = false): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            throw new MiitException('domain is required');
        }

        $timestamp = time();
        $client = new MiitClient($this->timeout);
        $authApi = new AuthApi($client);
        $captchaApi = new CaptchaApi($client);
        $icpApi = new IcpApi($client);
        $solver = new CaptchaSolver($client, $captchaApi);

        Debug::log($debug, 'step=auth timestamp=' . $timestamp);
        $authResponse = $authApi->auth($timestamp);
        Debug::log($debug, 'step=auth success=true expire=' . (string) ($authResponse['params']['expire'] ?? ''));

        $clientUid = CaptchaApi::newClientUid();
        Debug::log($debug, 'step=getCheckImagePoint clientUid=' . $clientUid);

        $challenge = $captchaApi->getCheckImagePoint($clientUid);
        $params = is_array($challenge['params'] ?? null) ? $challenge['params'] : [];
        $captchaUuid = (string) ($params['uuid'] ?? '');
        $bigImage = (string) ($params['bigImage'] ?? '');
        $smallImage = (string) ($params['smallImage'] ?? '');
        $height = (int) ($params['height'] ?? -1);
        if ($captchaUuid === '' || $bigImage === '' || $height < 0) {
            throw new UpstreamException('captcha challenge params missing', 'upstream query failed');
        }

        Debug::log($debug, 'step=getCheckImagePoint success=true captchaUUID=' . $captchaUuid . ' height=' . $height);

        $solved = $solver->solve($captchaUuid, $bigImage, $smallImage, $height, $debug);
        $checkResponse = $solved['response'];
        $sign = (string) ($checkResponse['params'] ?? '');
        if ($sign === '') {
            throw new UpstreamException('checkImage response missing sign', 'upstream query failed');
        }

        $client->setSign($sign);
        $client->setUuid($captchaUuid);

        Debug::log($debug, 'step=query endpoint=icpAbbreviateInfo/queryByCondition unitName=' . $domain . ' serviceType=1');
        $queryResponse = $icpApi->queryByCondition($domain);
        if (($queryResponse['success'] ?? false) !== true || ($queryResponse['code'] ?? 0) !== 200) {
            throw new UpstreamException(sprintf(
                'queryByCondition rejected: code=%s msg=%s',
                (string) ($queryResponse['code'] ?? ''),
                (string) ($queryResponse['msg'] ?? '')
            ), 'upstream query failed');
        }

        $queryParams = is_array($queryResponse['params'] ?? null) ? $queryResponse['params'] : [];
        $list = is_array($queryParams['list'] ?? null) ? $queryParams['list'] : [];
        if ($list === []) {
            throw new RecordNotFoundException('no ICP record found for ' . $domain);
        }

        Debug::log($debug, 'step=queryByCondition success=true', [
            'params_keys' => $this->keysOf($queryParams),
            'list_count' => count($list),
        ]);

        $item = $this->selectBestMatch($list, $domain, $debug);
        $identifiers = $this->extractIdentifiers($item);
        $mainId = $identifiers['mainId'];
        $domainId = $identifiers['domainId'];
        $serviceId = $identifiers['serviceId'];
        if ($mainId <= 0 || $domainId <= 0 || $serviceId <= 0) {
            $fallback = $this->fallbackToListDetail($item, $debug, 'queryByCondition', 'missing_valid_identifiers');
            if ($fallback !== null) {
                return $fallback;
            }

            Debug::log($debug, 'step=queryByCondition missing_valid_identifiers', [
                'selected' => $this->summarizeCandidate($item, $identifiers),
            ]);

            throw new UpstreamException(
                'queryByCondition response missing valid identifiers; keys=' . implode(',', $this->keysOf($item)),
                'upstream query failed'
            );
        }

        Debug::log($debug, sprintf(
            'step=queryDetail endpoint=icpAbbreviateInfo/queryDetailByServiceIdAndDomainId mainId=%d domainId=%d serviceId=%d',
            $mainId,
            $domainId,
            $serviceId
        ));

        try {
            $detailResponse = $icpApi->queryDetail($mainId, $domainId, $serviceId);
        } catch (UpstreamException $e) {
            $fallback = $this->fallbackToListDetail($item, $debug, 'queryDetail', $e->getMessage());
            if ($fallback !== null) {
                return $fallback;
            }

            throw $e;
        }

        if (($detailResponse['success'] ?? false) !== true || ($detailResponse['code'] ?? 0) !== 200) {
            $fallback = $this->fallbackToListDetail(
                $item,
                $debug,
                'queryDetail',
                'rejected code=' . (string) ($detailResponse['code'] ?? '') . ' msg=' . (string) ($detailResponse['msg'] ?? '')
            );
            if ($fallback !== null) {
                return $fallback;
            }

            throw new UpstreamException(sprintf(
                'detail response rejected: code=%s msg=%s',
                (string) ($detailResponse['code'] ?? ''),
                (string) ($detailResponse['msg'] ?? '')
            ), 'upstream query failed');
        }

        $detail = $detailResponse['params'] ?? null;
        if (!is_array($detail)) {
            $fallback = $this->fallbackToListDetail($item, $debug, 'queryDetail', 'params_missing');
            if ($fallback !== null) {
                return $fallback;
            }

            throw new UpstreamException('detail response params missing', 'upstream query failed');
        }

        $normalizedDetail = $this->detailFromListItem($detail);
        if ($normalizedDetail !== null) {
            return $normalizedDetail;
        }

        $fallback = $this->fallbackToListDetail($item, $debug, 'queryDetail', 'required_fields_missing');
        if ($fallback !== null) {
            return $fallback;
        }

        throw new UpstreamException(
            'detail response missing required fields; keys=' . implode(',', $this->keysOf($detail)),
            'upstream query failed'
        );
    }

    /** @param array<int, mixed> $list
     *  @return array<string, mixed>
     */
    private function selectBestMatch(array $list, string $domain, bool $debug = false): array
    {
        $fallback = null;
        $matchedCount = 0;
        $summaries = [];

        foreach ($list as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (!$this->candidateMatchesDomain($candidate, $domain)) {
                continue;
            }

            $matchedCount++;
            $identifiers = $this->extractIdentifiers($candidate);
            if (count($summaries) < 5) {
                $summaries[] = $this->summarizeCandidate($candidate, $identifiers);
            }

            if ($this->identifiersAreValid($identifiers)) {
                Debug::log($debug, 'step=queryByCondition selected_match', [
                    'matched_count' => $matchedCount,
                    'selected' => $this->summarizeCandidate($candidate, $identifiers),
                ]);

                return $candidate;
            }

            if ($fallback === null) {
                $fallback = $candidate;
            }
        }

        if (is_array($fallback)) {
            Debug::log($debug, 'step=queryByCondition exact_matches_without_valid_identifiers', [
                'matched_count' => $matchedCount,
                'candidates' => $summaries,
            ]);

            return $fallback;
        }

        Debug::log($debug, 'step=queryByCondition no_exact_match', [
            'domain' => $domain,
            'list_count' => count($list),
            'candidate_keys' => $this->candidateKeySamples($list),
        ]);

        throw new RecordNotFoundException('no exact ICP record found for ' . $domain, false);
    }

    /** @param array<string, mixed> $candidate */
    private function candidateMatchesDomain(array $candidate, string $domain): bool
    {
        $expected = strtolower(rtrim($domain, '.'));
        foreach (self::DOMAIN_FIELD_CANDIDATES as $field) {
            $value = $this->valueAtPath($candidate, $field);
            if (!is_scalar($value)) {
                continue;
            }

            if (strtolower(rtrim(trim((string) $value), '.')) === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{mainId: int, domainId: int, serviceId: int}
     */
    private function extractIdentifiers(array $item): array
    {
        return [
            'mainId' => $this->extractPositiveInt($item, self::ID_FIELD_CANDIDATES['mainId']),
            'domainId' => $this->extractPositiveInt($item, self::ID_FIELD_CANDIDATES['domainId']),
            'serviceId' => $this->extractPositiveInt($item, self::ID_FIELD_CANDIDATES['serviceId']),
        ];
    }

    /** @param array{mainId: int, domainId: int, serviceId: int} $identifiers */
    private function identifiersAreValid(array $identifiers): bool
    {
        return $identifiers['mainId'] > 0 && $identifiers['domainId'] > 0 && $identifiers['serviceId'] > 0;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $paths
     */
    private function extractPositiveInt(array $item, array $paths): int
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($item, $path);
            if (is_int($value)) {
                if ($value > 0) {
                    return $value;
                }

                continue;
            }

            if (is_float($value) && $value > 0 && floor($value) === $value) {
                return (int) $value;
            }

            if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
                return (int) $value;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $item */
    private function fallbackToListDetail(array $item, bool $debug, string $source, string $reason): ?array
    {
        $detail = $this->detailFromListItem($item);
        if ($detail === null) {
            return null;
        }

        Debug::log($debug, 'step=' . $source . ' fallback=list_item_detail', [
            'reason' => $reason,
            'keys' => $this->keysOf($item),
        ]);

        return $detail;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function detailFromListItem(array $item): ?array
    {
        $detail = $item;
        foreach (self::DETAIL_FIELDS as $field) {
            if (!array_key_exists($field, $detail)) {
                return null;
            }

            $value = $detail[$field];
            if (is_array($value) || is_object($value)) {
                return null;
            }

            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }

            $detail[$field] = $value;
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $item
     * @param array{mainId: int, domainId: int, serviceId: int} $identifiers
     * @return array<string, mixed>
     */
    private function summarizeCandidate(array $item, array $identifiers): array
    {
        return [
            'keys' => $this->keysOf($item),
            'domain' => $this->firstScalarValue($item, self::DOMAIN_FIELD_CANDIDATES),
            'mainId_raw' => $this->firstScalarValue($item, self::ID_FIELD_CANDIDATES['mainId']),
            'domainId_raw' => $this->firstScalarValue($item, self::ID_FIELD_CANDIDATES['domainId']),
            'serviceId_raw' => $this->firstScalarValue($item, self::ID_FIELD_CANDIDATES['serviceId']),
            'identifiers' => $identifiers,
            'list_detail_ready' => $this->detailFromListItem($item) !== null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $paths
     */
    private function firstScalarValue(array $item, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($item, $path);
            if (is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private function valueAtPath(array $item, string $path): mixed
    {
        $current = $item;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function keysOf(array $item): array
    {
        return array_slice(array_map(static fn (int|string $key): string => (string) $key, array_keys($item)), 0, 40);
    }

    /**
     * @param array<int, mixed> $list
     * @return list<list<string>>
     */
    private function candidateKeySamples(array $list): array
    {
        $samples = [];
        foreach ($list as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $samples[] = $this->keysOf($candidate);
            if (count($samples) >= 5) {
                break;
            }
        }

        return $samples;
    }
}
