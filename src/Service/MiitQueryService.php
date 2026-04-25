<?php

declare(strict_types=1);

namespace Miit\Service;

use Miit\Api\AuthApi;
use Miit\Api\CaptchaApi;
use Miit\Api\IcpApi;
use Miit\Api\MiitClient;
use Miit\Captcha\CaptchaSolver;
use Miit\Exception\MiitException;
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
        Debug::log($debug, 'step=getCheckImagePoint success=true captchaUUID=' . $captchaUuid . ' height=' . $height);

        $solved = $solver->solve($captchaUuid, $bigImage, $height, $debug);
        $checkResponse = $solved['response'];
        $client->setSign((string) ($checkResponse['params'] ?? ''));
        $client->setUuid($captchaUuid);

        Debug::log($debug, 'step=query endpoint=icpAbbreviateInfo/queryByCondition unitName=' . $domain . ' serviceType=1');
        $queryResponse = $icpApi->queryByCondition($domain);
        $queryParams = is_array($queryResponse['params'] ?? null) ? $queryResponse['params'] : [];
        $list = is_array($queryParams['list'] ?? null) ? $queryParams['list'] : [];
        if ($list === []) {
            throw new MiitException('queryByCondition returned no records for ' . $domain);
        }

        $item = is_array($list[0]) ? $list[0] : [];
        $mainId = (int) ($item['mainId'] ?? 0);
        $domainId = (int) ($item['domainId'] ?? 0);
        $serviceId = (int) ($item['serviceId'] ?? 0);

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
