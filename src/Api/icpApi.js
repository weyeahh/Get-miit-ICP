export class IcpApi {
  constructor(client) {
    this.client = client;
  }

  async queryByCondition(keyword, pageSize = 200) {
    return this.client.postJson('icpAbbreviateInfo/queryByCondition', {
      pageNum: 1,
      pageSize,
      unitName: keyword,
      serviceType: 1,
    });
  }

  async queryDetail(mainId, domainId, serviceId) {
    return this.client.postJson('icpAbbreviateInfo/queryDetailByServiceIdAndDomainId', {
      mainId,
      domainId,
      serviceId,
    });
  }
}
