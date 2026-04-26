export class IcpApi {
  constructor(client) {
    this.client = client;
  }

  async queryByCondition(domain) {
    return this.client.postJson('icpAbbreviateInfo/queryByCondition', {
      pageNum: '',
      pageSize: '',
      unitName: domain,
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
