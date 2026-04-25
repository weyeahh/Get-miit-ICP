# Get MIIT ICP

一个基于 PHP 的 MIIT 备案查询服务，接收 `GET` 请求中的 `domain` 参数，返回工信部备案详细信息，适配了最新版工信部查询系统的滑块验证。

本项目基于 [fuckmiit](https://github.com/Mxmilu666/fuckmiit) 编写，保留了原项目的核心思路，添加了更多的响应处理：

1. 复刻工信部备案查询接口调用链路。
2. 处理鉴权请求。
3. 获取并求解滑块验证码。
4. 查询备案列表并进一步获取备案详情。

当前版本将原始 Go 库重构为更适合部署的 PHP Web 服务形式，提供统一的 HTTP 接口入口。

## Features

1. 基于 HTTP GET 的简单调用方式。
2. 纯 PHP 实现，不依赖 Composer。
3. 内置工信部接口请求头、Cookie 会话、鉴权流程。
4. 保留滑块验证码识别和偏移试探逻辑。
5. 返回统一 JSON 响应，适合作为 API 服务集成。

## Project Origin

本项目并非从零实现，核心协议分析与验证码处理思路来源于原项目：

- [Mxmilu666/fuckmiit](https://github.com/Mxmilu666/fuckmiit)

本仓库在其基础上进行了以下重构：

1. 从 Go 库改造为 PHP Web 服务。
2. 按职责拆分为 `Api`、`Captcha`、`Service`、`Http` 等模块。
3. 增加统一的 HTTP 入口与 JSON 响应格式。
4. 调整接口输出结构，便于前后端或第三方系统直接对接。

## Project Structure

```text
.
|- public/
|  `- index.php
|- src/
|  |- Api/
|  |  |- AuthApi.php
|  |  |- CaptchaApi.php
|  |  |- IcpApi.php
|  |  `- MiitClient.php
|  |- Captcha/
|  |  |- CaptchaSolver.php
|  |  `- Rect.php
|  |- Exception/
|  |  `- MiitException.php
|  |- Http/
|  |  `- JsonResponse.php
|  |- Service/
|  |  `- MiitQueryService.php
|  |- Support/
|  |  `- Debug.php
|  `- bootstrap.php
`- README.md
```

## Architecture

### Request Flow

项目执行链路如下：

1. 客户端发起请求：`GET /?domain=example.com`
2. `public/index.php` 读取 `domain` 参数
3. `MiitQueryService` 执行完整查询流程
4. `AuthApi` 请求 `/auth` 获取 `Token`
5. `CaptchaApi` 请求 `/image/getCheckImagePoint` 获取验证码挑战
6. `CaptchaSolver` 在本地识别缺口坐标，并调用 `/image/checkImage`
7. `IcpApi` 请求 `/icpAbbreviateInfo/queryByCondition`
8. 使用返回的 `mainId`、`domainId`、`serviceId` 请求详情接口
9. 服务将详情字段包装为统一 JSON 返回

### Module Responsibilities

1. `public/index.php`
   HTTP 入口，负责参数读取和响应输出。

2. `src/Api/MiitClient.php`
   通用 HTTP 客户端，维护请求头、Cookie 和超时控制。

3. `src/Api/AuthApi.php`
   封装 `auth` 接口和 `authKey` 生成逻辑。

4. `src/Api/CaptchaApi.php`
   封装验证码获取与校验接口。

5. `src/Captcha/CaptchaSolver.php`
   验证码识别核心模块，负责：
   读取图片、识别缺口、枚举候选横坐标、调用校验接口。

6. `src/Api/IcpApi.php`
   封装备案列表和详情查询接口。

7. `src/Service/MiitQueryService.php`
   业务编排层，串起完整的 MIIT 查询流程。

## Requirements

运行环境要求：

1. PHP 8.1 或更高版本。
2. 启用 `curl` 扩展。
3. 启用 `gd` 扩展。

建议在 Linux 或具备完整 PHP CLI 环境的服务器上运行。

## Quick Start

使用 PHP 内置服务器启动：

```bash
php -S 127.0.0.1:8080 -t public
```

然后访问：

```text
http://127.0.0.1:8080/?domain=baidu.com
```

调试模式：

```text
http://127.0.0.1:8080/?domain=baidu.com&debug=1
```

## API

### Request

Method:

```http
GET /
```

Query Parameters:

1. `domain` required
   要查询的域名。

2. `debug` optional
   传入 `1` 时输出调试日志到标准错误流。

### Success Response

HTTP status: `200`

```json
{
  "code": 200,
  "message": "successful",
  "data": {
    "Domain": "baidu.com",
    "UnitName": "北京百度网讯科技有限公司",
    "MainLicence": "京ICP证030173号",
    "ServiceLicence": "京ICP证030173号-1",
    "NatureName": "企业",
    "LeaderName": "李彦宏",
    "UpdateRecordTime": "2026-01-01 00:00:00"
  }
}
```

### Error Response

缺少参数时，HTTP status: `400`

```json
{
  "code": 400,
  "message": "domain parameter is required",
  "data": null
}
```

未找到备案记录时，HTTP status: `404`

```json
{
  "code": 404,
  "message": "no ICP record found",
  "data": {
    "domain": "example.com",
    "detail": "no ICP record found for example.com"
  }
}
```

上游接口异常或被风控等系统错误时，HTTP status: `500`

```json
{
  "code": 500,
  "message": "upstream query failed",
  "data": {
    "domain": "example.com",
    "detail": "具体错误信息"
  }
}
```

## Response Field Mapping

当前 `data` 字段基于工信部详情接口响应中的 `params` 进行映射：

1. `domain` -> `Domain`
2. `unitName` -> `UnitName`
3. `mainLicence` -> `MainLicence`
4. `serviceLicence` -> `ServiceLicence`
5. `natureName` -> `NatureName`
6. `leaderName` -> `LeaderName`
7. `updateRecordTime` -> `UpdateRecordTime`

## Implementation Notes

为了尽可能保持与原项目一致，当前 PHP 版本沿用了原始实现的几个核心策略：

1. 使用固定请求头模拟浏览器环境。
2. 使用 Cookie 持续维持服务端会话状态。
3. 使用 `md5("testtest" + timestamp)` 构造 `authKey`。
4. 使用验证码大图中的缺口区域进行本地识别。
5. 在识别出的偏移量附近做小范围穷举校验。
6. 列表查询后默认使用第一条结果获取详情。
7. 当列表为空时，返回 404 业务响应，而不是将其视为系统异常。
8. 当上游接口异常、访问受限或疑似被风控时，返回 500 详细错误响应。

## Limitations

该项目本质上仍然依赖目标站点当前的协议和验证码样式，因此存在天然脆弱性。

主要限制包括：

1. 如果工信部接口字段发生变化，服务可能失效。
2. 如果验证码颜色、形状或返回数据结构改变，识别逻辑可能失效。
3. 当前没有实现自动重试、限流处理或高级恢复机制。
4. 列表结果默认取第一条记录，未做复杂筛选。

这意味着该项目更适合作为特定场景下的工程化工具，而非长期稳定的官方兼容方案。

## Debugging

当启用 `debug=1` 时，服务会输出流程日志，例如：

1. `step=auth`
2. `step=getCheckImagePoint`
3. `step=detect`
4. `step=checkImage attempt_left=...`
5. `step=query`
6. `step=queryDetail`

这些日志主要用于排查验证码识别失败、接口返回异常或查询链路中断问题。
