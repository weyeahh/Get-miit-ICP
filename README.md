# Get MIIT ICP

一个基于 PHP 的 MIIT 备案查询服务，接收 `GET` 请求中的 `domain` 参数，返回工信部备案详细信息，适配了最新版工信部查询系统的滑块验证。

本项目基于 [fuckmiit](https://github.com/Mxmilu666/fuckmiit) 编写，保留了原项目的核心思路，添加了更多的响应处理：

1. 复刻工信部备案查询接口调用链路。
2. 处理鉴权请求。
3. 获取并求解滑块验证码。
4. 查询备案列表并进一步获取备案详情。

当前版本将原始 Go 库重构为更适合部署的 PHP Web 服务形式，并在原始能力之上补充了输入校验、限流、缓存、错误脱敏、环境预检和失败冷却机制，以降低上游风控风险。

## Features

1. 基于 HTTP GET 的简单调用方式。
2. 纯 PHP 实现，不依赖 Composer 运行，但提供 `composer.json` 用于环境约束与自动加载声明。
3. 内置工信部接口请求头、Cookie 会话、鉴权流程。
4. 保留滑块验证码识别与偏移试探逻辑。
5. 增加 domain 规范化与基础格式校验。
6. 增加全局、每 IP、每 domain 的文件限流与失败冷却。
7. 增加同域 singleflight 查询锁，避免缓存未命中时并发击穿上游。
8. 增加成功缓存和空结果短缓存，减少重复请求上游。
9. 错误对外脱敏，对内写入服务端日志。
10. 日志写入采用 best-effort 策略，日志失败不会破坏 API 响应。
11. 增加缓存 schema version、响应编码保护、错误分类、环境预检与基础测试骨架。

## Project Origin

本项目并非从零实现，核心协议分析与验证码处理思路来源于原项目：

- [Mxmilu666/fuckmiit](https://github.com/Mxmilu666/fuckmiit)

本仓库在其基础上进行了以下重构：

1. 从 Go 库改造为 PHP Web 服务。
2. 按职责拆分为 `Api`、`Captcha`、`Service`、`Http`、`Cache`、`RateLimit`、`Validation`、`Config` 等模块。
3. 增加统一的 HTTP 入口与 JSON 响应格式。
4. 增加频控、singleflight、缓存、关键字段校验、错误分类、环境预检和错误脱敏。

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
|  |- Config/
|  |  `- AppConfig.php
|  |- Exception/
|  |  |- EnvironmentException.php
|  |  |- InternalErrorException.php
|  |  |- MiitException.php
|  |  |- RateLimitException.php
|  |  |- RecordNotFoundException.php
|  |  |- StorageException.php
|  |  |- UpstreamException.php
|  |  `- ValidationException.php
|  |- Http/
|  |  `- JsonResponse.php
|  |- RateLimit/
|  |  |- DomainQueryLock.php
|  |  |- FileRateLimiter.php
|  |  `- QueryGuard.php
|  |- Service/
|  |  `- MiitQueryService.php
|  |- Support/
|  |  |- AppPaths.php
|  |  |- ClientIp.php
|  |  |- Debug.php
|  |  |- DetailSanitizer.php
|  |  |- EnvironmentGuard.php
|  |  |- FileMutex.php
|  |  |- Logger.php
|  |  `- ResponseFormatter.php
|  |- Validation/
|  |  `- DomainNormalizer.php
|  `- bootstrap.php
|- storage/
|  |- cache/
|  |- locks/
|  |- logs/
|  `- ratelimit/
|- tests/
|  |- bootstrap.php
|  |- DomainNormalizerTest.php
|  |- EnvironmentGuardTest.php
|  |- JsonResponseTest.php
|  |- QueryCacheVersionTest.php
|  |- ResponseFormatterTest.php
|  `- run.php
|- composer.json
`- README.md
```

## Architecture

### Request Flow

项目执行链路如下：

1. 客户端发起请求：`GET /?domain=example.com`
2. `public/index.php` 读取原始参数并初始化配置对象 `AppConfig`
3. `EnvironmentGuard` 在入口阶段检查 `curl`、`gd`、`json` 扩展是否可用
4. `DomainNormalizer` 执行域名规范化与校验
5. `QueryCache` 优先命中成功缓存或空结果缓存
6. `DomainQueryLock` 为同一 domain 提供 singleflight 查询锁
7. 获取 domain 锁成功后再次读取缓存，避免锁等待后的重复上游查询
8. 只有真正准备访问上游时，`QueryGuard` 才执行全局、IP、domain 频控与冷却判断
9. `MiitQueryService` 执行完整查询流程
10. `AuthApi` 请求 `/auth` 获取 `Token`
11. `CaptchaApi` 请求 `/image/getCheckImagePoint` 获取验证码挑战
12. `CaptchaSolver` 在本地识别缺口坐标，并调用 `/image/checkImage`
13. `IcpApi` 请求 `/icpAbbreviateInfo/queryByCondition`
14. `MiitQueryService` 对列表结果执行精确匹配优先选择
15. 使用返回的 `mainId`、`domainId`、`serviceId` 请求详情接口
16. `ResponseFormatter` 在真正写成功缓存前校验详情字段完整性
17. 成功结果进入带 schema version 的缓存并返回
18. 失败按异常类型分类，分别映射为参数错误、频控错误、存储错误、环境错误、上游错误或内部错误

### Module Responsibilities

1. `public/index.php`
   HTTP 入口，负责参数读取、配置加载、环境预检、缓存命中、singleflight、限流、错误分类和响应输出。

2. `src/Validation/DomainNormalizer.php`
   负责域名规范化、长度限制、字符合法性和标签校验。

3. `src/Config/AppConfig.php`
   负责缓存 TTL、限流阈值、singleflight 等待时间、日志截断长度、debug 开关等配置的集中定义，并支持从环境变量覆盖默认值。

4. `src/RateLimit/QueryGuard.php`
   负责全局、IP、domain 限流和失败冷却策略。当前通过 `consumeAll()` 实现多维限流的原子消费，避免单维失败污染其他维度计数。

5. `src/RateLimit/DomainQueryLock.php`
   负责同一 domain 查询过程的 singleflight 控制。

6. `src/Cache/QueryCache.php`
   负责成功缓存与空结果缓存，并通过 schema version 隔离未来结构变化。

7. `src/Cache/FileCache.php`
   负责缓存文件的加锁读取、完整性校验写入和轻量级过期清理。

8. `src/Api/MiitClient.php`
   通用 HTTP 客户端，维护请求头、Cookie、超时控制和上游错误截断。

9. `src/Api/AuthApi.php`
   封装 `auth` 接口和 `authKey` 生成逻辑，并把鉴权协议失败统一归类为上游错误。

10. `src/Api/CaptchaApi.php`
    封装验证码获取与校验接口，并把验证码协议失败统一归类为上游错误。

11. `src/Captcha/CaptchaSolver.php`
    验证码识别核心模块，负责读取图片、识别缺口、枚举候选横坐标、调用校验接口。

12. `src/Api/IcpApi.php`
    封装备案列表和详情查询接口。

13. `src/Service/MiitQueryService.php`
    业务编排层，串起完整的 MIIT 查询流程，并补充关键字段校验和列表精确匹配优先策略。

14. `src/Support/Logger.php`
    负责将详细错误写入本地日志，并在日志失败时降级到 `php://stderr`。

15. `src/Http/JsonResponse.php`
    负责响应输出，并在 JSON 编码失败时输出保底错误 JSON。

16. `src/Support/EnvironmentGuard.php`
    负责运行前检查 `curl`、`gd`、`json` 扩展是否存在。

17. `src/Support/ResponseFormatter.php`
    负责最终成功响应封装，并显式校验详情必填字段。

## Requirements

运行环境要求：

1. PHP 8.1 或更高版本。
2. 启用 `curl` 扩展。
3. 启用 `gd` 扩展。
4. 启用 `json` 扩展。
5. 若希望日志截断完全按多字节字符边界处理，建议启用 `mbstring`，但当前实现即使缺少 `mbstring` 也会回退到字节截断而不会直接 fatal。
6. 运行用户需要对项目目录下的 `storage/` 有读写权限。
7. 建议保留仓库内的 `.gitignore` 和 `storage/.gitkeep` 文件，避免运行产物被误提交。
8. 若需要验证 `composer.json` 语义，需额外安装 Composer CLI。

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

由于默认关闭 query 参数控制的 debug，除非在 `AppConfig` 中显式开启 `debug.allow_query_toggle`，否则 `debug=1` 不会生效。

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
   仅在配置允许时启用调试日志输出。

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
    "detail": "upstream query failed"
  }
}
```

本地存储或环境未就绪时，HTTP status: `500`

```json
{
  "code": 500,
  "message": "service environment is not ready",
  "data": {
    "domain": "example.com",
    "detail": "service environment is not ready"
  }
}
```

内部错误时，HTTP status: `500`

```json
{
  "code": 500,
  "message": "internal server error",
  "data": {
    "domain": "example.com",
    "detail": "the service encountered an internal error"
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
2. `EnvironmentGuard` 会在入口层主动检查 `curl`、`gd`、`json` 扩展，避免服务运行到中途才因缺扩展崩溃。
3. 缓存优先于上游频控，缓存命中不会消耗上游配额。
4. 同一 domain 的并发请求先竞争 singleflight 锁，只有真正准备访问上游的请求才在锁内执行频控计数，避免“未出站先扣额度”的限流语义污染。
5. 全局、IP、domain 限流都通过 `AppConfig` 配置化，而不是硬编码在业务逻辑里，并支持通过环境变量覆盖默认值。
6. `QueryGuard` 通过 `consumeAll()` 一次性消费多维限流状态，避免 global 先扣、IP 后失败、domain 再失败时的计数污染。
7. 上游失败后会进入短暂冷却，避免连续打上游。
8. `FileRateLimiter` 的频控窗口文件和 cooldown 文件都使用文件锁保护，读取 cooldown 时也加共享锁，避免并发下漏读冷却状态。
9. `FileCache` 读取缓存时使用共享锁，写入缓存时使用独占锁，并校验编码、截断、写入长度和 flush 完整性，避免并发读写读到半写入内容。
10. `FileCache` 和 `FileRateLimiter` 都带有轻量级随机 GC，用于删除过期缓存、过期 cooldown 和陈旧限流窗口文件，降低 storage 无限增长风险。
11. 同域请求在拿到 domain 锁后会再次读取 success/miss cache，降低锁等待期间的重复上游访问。
12. 同域等待窗口不再固定为 2 秒，而是通过配置化的超时和轮询间隔控制；当前默认值已收紧为更保守的等待窗口，以减少 worker 长时间占用。
13. 成功结果默认缓存 24 小时。
14. 无备案记录默认缓存 30 分钟，并且只有在列表接口成功、列表为空或精确匹配失败且异常被明确标记为可缓存时才会写入 miss cache。
15. 缓存条目携带 `_schema_version`，未来响应结构变化时可以通过版本变更使旧缓存自动失效。
16. 验证码偏移尝试次数已缩减，避免单次请求过度放大。
17. `MiitQueryService` 对列表结果不再机械相信第一条，而是优先寻找与查询 domain 精确匹配的项；找不到精确匹配时返回 `404`，避免错误主体写入成功缓存。
18. 错误被分成参数错误、频控错误、存储错误、环境错误、上游错误和内部错误，不再把所有异常粗暴归类为上游失败。
19. `AuthApi`、`CaptchaApi`、`MiitClient` 和 `MiitQueryService` 中的上游协议错误统一升级为 `UpstreamException`，保证冷却策略只针对真正的上游故障触发。
20. `MiitClient` 会截断写入日志的上游错误详情，防止异常响应体无限放大日志体积。
21. `DetailSanitizer` 优先使用 `mbstring` 做 UTF-8 安全截断；若环境缺少 `mbstring`，则自动回退为字节截断而不是 fatal。
22. `Logger` 采用 best-effort 策略，日志目录不可写时会降级尝试写入 `php://stderr`，不会再向外抛异常。
23. `JsonResponse` 会检查 `json_encode()` 结果，编码失败时输出保底 JSON，并同步把 HTTP 状态修正为 `500`，避免 HTTP 状态和 JSON body code 矛盾。
24. `ResponseFormatter` 会校验必填详情字段，不再用空字符串静默吞掉字段缺失；同时先格式化、后写成功缓存，避免不可渲染数据进入 success cache。
25. storage 目录在运行时会校验可创建、可写，避免限流与缓存静默失效。
26. 初始化阶段异常也会进入统一 JSON 错误出口，避免 API 返回非 JSON 错误页。

## Implementation Notes

为了尽可能保持与原项目一致，当前 PHP 版本沿用了原始实现的几个核心策略：

1. 使用固定请求头模拟浏览器环境。
2. 使用 Cookie 持续维持服务端会话状态。
3. 使用 `md5("testtest" + timestamp)` 构造 `authKey`。
4. 使用验证码大图中的缺口区域进行本地识别。
5. 列表查询后优先进行精确匹配，再根据业务语义返回 `404`，而不是盲目回退第一条结果。
6. 只有在列表接口本身成功且结果为空，或精确匹配失败时，才返回 `404`。
7. 上游异常、签名失效、鉴权失败、风控等情况统一落到 `UpstreamException` 路径，而本地存储与环境问题会走不同分类。
8. 入口层的组件初始化、环境预检、缓存、锁、限流和查询都走统一异常出口。
9. 日志系统是辅助能力，失败时不会反向影响主响应契约。
10. 调试输出默认关闭，只有配置允许时才接受 URL 参数启用。

## Storage

项目会在运行时自动创建 `storage/` 目录，用于：

1. `storage/cache/`
   保存成功缓存和空结果缓存。

2. `storage/ratelimit/`
   保存频控窗口与冷却状态。

3. `storage/locks/`
   保存 singleflight 锁文件。

4. `storage/logs/`
   保存结构化错误日志。

这些文件都是本地文件实现，适合单机部署。如果你在多实例环境中运行，建议后续替换为 Redis 等共享存储。

项目默认通过 `.gitignore` 忽略 `storage/` 下的运行产物，并使用 `.gitkeep` 保留必要目录结构。

测试目录中的缓存版本测试会写入 `storage/test-cache/`，该目录属于测试产物，必要时可在 CI 或本地测试后清理。

## Limitations

该项目本质上仍然依赖目标站点当前的协议和验证码样式，因此存在天然脆弱性。

主要限制包括：

1. 如果工信部接口字段发生变化，服务可能失效。
2. 如果验证码颜色、形状或返回数据结构改变，识别逻辑可能失效。
3. 当前缓存、锁和频控基于本地文件，适合单机，不适合直接横向扩容。
4. 列表结果虽然增加了精确匹配优先，但仍受上游字段质量影响。
5. 当前没有 stale 数据回退策略，500 时不会回放历史成功缓存。
6. 同域 singleflight 当前是等待后回读缓存的模式，不是长轮询队列或作业系统。
7. 仓库附带了 `composer.json` 和基础测试骨架，但当前环境若没有 Composer CLI 仍无法执行 `composer validate --strict`。
8. 当前测试仍然只是基础测试，不足以替代完整的并发、锁竞争和真实上游集成测试。

这意味着该项目更适合作为特定场景下的工程化工具，而非长期稳定的官方兼容方案。

## Debugging

当配置允许且启用 `debug=1` 时，服务会输出流程日志，例如：

1. `step=auth`
2. `step=getCheckImagePoint`
3. `step=detect`
4. `step=checkImage attempt_left=...`
5. `step=query`
6. `step=queryDetail`

服务端详细错误会写入 `storage/logs/`，用于排查验证码识别失败、接口返回异常、频控触发和上游风控问题。

基础测试骨架可在具备 PHP CLI 的环境下运行：

```bash
php tests/run.php
```
