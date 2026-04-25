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
use Miit\Support\Debug;

final class MiitQueryService
{
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
        $height = (int) ($params['height'] ?? -1);
        if ($captchaUuid === '' || $bigImage === '' || $height < 0) {
            throw new MiitException('captcha challenge params missing');
        }

        Debug::log($debug, 'step=getCheckImagePoint success=true captchaUUID=' . $captchaUuid . ' height=' . $height);

        $solved = $solver->solve($captchaUuid, $bigImage, $height, $debug);
        $checkResponse = $solved['response'];
        $sign = (string) ($checkResponse['params'] ?? '');
        if ($sign === '') {
            throw new MiitException('checkImage response missing sign');
        }

        $client->setSign($sign);
        $client->setUuid($captchaUuid);

        Debug::log($debug, 'step=query endpoint=icpAbbreviateInfo/queryByCondition unitName=' . $domain . ' serviceType=1');
        $queryResponse = $icpApi->queryByCondition($domain);
        if (($queryResponse['success'] ?? false) !== true || ($queryResponse['code'] ?? 0) !== 200) {
            throw new MiitException(sprintf(
                'queryByCondition rejected: code=%s msg=%s',
                (string) ($queryResponse['code'] ?? ''),
                (string) ($queryResponse['msg'] ?? '')
            ));
        }

        $queryParams = is_array($queryResponse['params'] ?? null) ? $queryResponse['params'] : [];
        $list = is_array($queryParams['list'] ?? null) ? $queryParams['list'] : [];
        if ($list === []) {
            throw new RecordNotFoundException('no ICP record found for ' . $domain);
        }

        $item = is_array($list[0]) ? $list[0] : [];
        $mainId = (int) ($item['mainId'] ?? 0);
        $domainId = (int) ($item['domainId'] ?? 0);
        $serviceId = (int) ($item['serviceId'] ?? 0);
        if ($mainId <= 0 || $domainId <= 0 || $serviceId <= 0) {
            throw new MiitException('queryByCondition response missing valid identifiers');
        }

        Debug::log($debug, sprintf(
            'step=queryDetail endpoint=icpAbbreviateInfo/queryDetailByServiceIdAndDomainId mainId=%d domainId=%d serviceId=%d',
            $mainId,
            $domainId,
            $serviceId
        ));

        $detailResponse = $icpApi->queryDetail($mainId, $domainId, $serviceId);
        if (($detailResponse['success'] ?? false) !== true || ($detailResponse['code'] ?? 0) !== 200) {
            throw new MiitException(sprintf(
                'detail response rejected: code=%s msg=%s',
                (string) ($detailResponse['code'] ?? ''),
                (string) ($detailResponse['msg'] ?? '')
            ));
        }

        $detail = $detailResponse['params'] ?? null;
        if (!is_array($detail)) {
            throw new MiitException('detail response params missing');
        }

        return $detail;
    }
}
