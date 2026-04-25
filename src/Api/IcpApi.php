<?php

declare(strict_types=1);

namespace Miit\Api;

final class IcpApi
{
    public function __construct(private readonly MiitClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function queryByCondition(string $domain): array
    {
        return $this->client->postJson('icpAbbreviateInfo/queryByCondition', [
            'pageNum' => '',
            'pageSize' => '',
            'unitName' => $domain,
            'serviceType' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    public function queryDetail(int $mainId, int $domainId, int $serviceId): array
    {
        return $this->client->postJson('icpAbbreviateInfo/queryDetailByServiceIdAndDomainId', [
            'mainId' => $mainId,
            'domainId' => $domainId,
            'serviceId' => $serviceId,
        ]);
    }
}
