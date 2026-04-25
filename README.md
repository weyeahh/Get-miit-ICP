# Get MIIT ICP

一个基于 PHP 的 MIIT 备案查询服务，接收 `GET` 请求中的 `domain` 参数，返回工信部备案详细信息，适配了最新版工信部查询系统的滑块验证。

本项目基于 [fuckmiit](https://github.com/Mxmilu666/fuckmiit) 编写，保留了原项目的核心思路，添加了更多的响应处理：

1. 复刻工信部备案查询接口调用链路。
2. 处理鉴权请求。
3. 获取并求解滑块验证码。
4. 查询备案列表并进一步获取备案详情。

当前版本将原始 Go 库重构为更适合部署的 PHP Web 服务形式，并在原始能力之上补充了输入校验、限流、缓存、错误脱敏和失败冷却机制，以降低上游风控风险。

## Features

1. 基于 HTTP GET 的简单调用方式。
2. 纯 PHP 实现，不依赖 Composer。
3. 内置工信部接口请求头、Cookie 会话、鉴权流程。
4. 保留滑块验证码识别与偏移试探逻辑。
5. 增加 domain 规范化与基础格式校验。
6. 增加全局、每 IP、每 domain 的文件限流与失败冷却。
7. 增加成功缓存和空结果短缓存，减少重复请求上游。
8. 错误对外脱敏，对内写入服务端日志。

## Project Origin

本项目并非从零实现，核心协议分析与验证码处理思路来源于原项目：

- [Mxmilu666/fuckmiit](https://github.com/Mxmilu666/fuckmiit)

本仓库在其基础上进行了以下重构：

1. 从 Go 库改造为 PHP Web 服务。
2. 按职责拆分为 `Api`、`Captcha`、`Service`、`Http`、`Cache`、`RateLimit`、`Validation` 等模块。
3. 增加统一的 HTTP 入口与 JSON 响应格式。
4. 增加频控、缓存、关键字段校验和错误脱敏。

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
|  |- Cache/
|  |  |- FileCache.php
|  |  `- QueryCache.php
|  |- Captcha/
|  |  |- CaptchaSolver.php
|  |  `- Rect.php
|  |- Exception/
|  |  |- MiitException.php
|  |  |- RateLimitException.php
|  |  |- RecordNotFoundException.php
|  |  `- ValidationException.php
|  |- Http/
|  |  `- JsonResponse.php
|  |- RateLimit/
|  |  |- FileRateLimiter.php
|  |  `- QueryGuard.php
|  |- Service/
|  |  `- MiitQueryService.php
|  |- Support/
|  |  |- AppPaths.php
|  |  |- ClientIp.php
|  |  |- Debug.php
|  |  |- Logger.php
|  |  `- ResponseFormatter.php
|  |- Validation/
|  |  `- DomainNormalizer.php
|  `- bootstrap.php
|- storage/
|  |- cache/
|  |- logs/
|  `- ratelimit/
`- README.md
```

## Architecture

### Request Flow

项目执行链路如下：

1. 客户端发起请求：`GET /?domain=example.com`
2. `public/index.php` 读取原始参数
3. `DomainNormalizer` 执行域名规范化与校验
4. `QueryGuard` 执行全局、IP、domain 频控与冷却判断
5. `QueryCache` 优先命中成功缓存或空结果缓存
6. 未命中缓存时，`MiitQueryService` 执行完整查询流程
7. `AuthApi` 请求 `/auth` 获取 `Token`
8. `CaptchaApi` 请求 `/image/getCheckImagePoint` 获取验证码挑战
9. `CaptchaSolver` 在本地识别缺口坐标，并调用 `/image/checkImage`
10. `IcpApi` 请求 `/icpAbbreviateInfo/queryByCondition`
11. 使用返回的 `mainId`、`domainId`、`serviceId` 请求详情接口
12. 成功结果进入缓存并返回
13. 上游异常则记录日志并返回脱敏后的错误响应

### Module Responsibilities

1. `public/index.php`
   HTTP 入口，负责参数读取、限流、缓存命中和响应输出。

2. `src/Validation/DomainNormalizer.php`
   负责域名规范化、长度限制、字符合法性和标签校验。

3. `src/RateLimit/QueryGuard.php`
   负责全局、IP、domain 限流和失败冷却策略。

4. `src/Cache/QueryCache.php`
   负责成功缓存与空结果缓存。

5. `src/Api/MiitClient.php`
   通用 HTTP 客户端，维护请求头、Cookie 和超时控制。

6. `src/Api/AuthApi.php`
   封装 `auth` 接口和 `authKey` 生成逻辑。

7. `src/Api/CaptchaApi.php`
   封装验证码获取与校验接口。

8. `src/Captcha/CaptchaSolver.php`
   验证码识别核心模块，负责读取图片、识别缺口、枚举候选横坐标、调用校验接口。

9. `src/Api/IcpApi.php`
   封装备案列表和详情查询接口。

10. `src/Service/MiitQueryService.php`
    业务编排层，串起完整的 MIIT 查询流程，并补充关键字段校验。

11. `src/Support/Logger.php`
    负责将详细错误写入本地日志，而不是直接返回客户端。

## Requirements

运行环境要求：

1. PHP 8.1 或更高版本。
2. 启用 `curl` 扩展。
3. 启用 `gd` 扩展。
4. 运行用户需要对项目目录下的 `storage/` 有读写权限。

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
   要查询的域名。系统会自动执行规范化与格式校验。

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

参数非法时，HTTP status: `400`

```json
{
  "code": 400,
  "message": "domain format is invalid",
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

请求过于频繁或处于冷却中时，HTTP status: `429`

```json
{
  "code": 429,
  "message": "too many requests",
  "data": {
    "domain": "example.com"
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
    "detail": "the upstream service rejected or failed the query"
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

## Governance Strategy

为了降低上游风控风险，当前版本增加了治理层：

1. domain 参数进入主链路前会做规范化与格式校验。
2. 增加全局、每 IP、每 domain 的限流。
3. 上游失败后会进入短暂冷却，避免连续打上游。
4. 成功结果默认缓存 24 小时。
5. 无备案记录默认缓存 30 分钟。
6. 验证码偏移尝试次数已缩减，避免单次请求过度放大。
7. 客户端只看到脱敏后的错误消息，详细错误进入本地日志。

## Implementation Notes

为了尽可能保持与原项目一致，当前 PHP 版本沿用了原始实现的几个核心策略：

1. 使用固定请求头模拟浏览器环境。
2. 使用 Cookie 持续维持服务端会话状态。
3. 使用 `md5("testtest" + timestamp)` 构造 `authKey`。
4. 使用验证码大图中的缺口区域进行本地识别。
5. 列表查询后默认使用第一条结果获取详情。
6. 只有在列表接口本身成功且结果为空时，才返回 `404`。
7. 上游异常、签名失效、鉴权失败、风控等情况统一落到 `500`。

## Storage

项目会在运行时自动创建 `storage/` 目录，用于：

1. `storage/cache/`
   保存成功缓存和空结果缓存。

2. `storage/ratelimit/`
   保存频控窗口与冷却状态。

3. `storage/logs/`
   保存结构化错误日志。

这些文件都是本地文件实现，适合单机部署。如果你在多实例环境中运行，建议后续替换为 Redis 等共享存储。

## Limitations

该项目本质上仍然依赖目标站点当前的协议和验证码样式，因此存在天然脆弱性。

主要限制包括：

1. 如果工信部接口字段发生变化，服务可能失效。
2. 如果验证码颜色、形状或返回数据结构改变，识别逻辑可能失效。
3. 当前缓存与频控基于本地文件，适合单机，不适合直接横向扩容。
4. 列表结果默认取第一条记录，未做复杂筛选。
5. 当前没有 stale 数据回退策略，500 时不会回放历史成功缓存。

这意味着该项目更适合作为特定场景下的工程化工具，而非长期稳定的官方兼容方案。

## Debugging

当启用 `debug=1` 时，服务会输出流程日志，例如：

1. `step=auth`
2. `step=getCheckImagePoint`
3. `step=detect`
4. `step=checkImage attempt_left=...`
5. `step=query`
6. `step=queryDetail`

服务端详细错误会写入 `storage/logs/`，用于排查验证码识别失败、接口返回异常、频控触发和上游风控问题。

## License

请根据你的实际开源策略补充或更新许可证内容。

如果你希望沿用原始项目的开源精神，建议在发布前明确当前仓库的 License 文件内容，并确认与来源项目兼容。
