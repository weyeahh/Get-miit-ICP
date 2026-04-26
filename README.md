# Get MIIT ICP

一个基于 Node.js 的 MIIT 备案查询服务，接收 `GET` 请求中的 `domain` 参数，返回工信部备案详细信息，适配了最新版工信部查询系统的滑块验证。

本项目基于 [fuckmiit](https://github.com/Mxmilu666/fuckmiit) 编写，保留了原项目的核心思路，添加了更多的响应处理：

1. 复刻工信部备案查询接口调用链路。
2. 处理鉴权请求。
3. 获取并求解滑块验证码。
4. 查询备案列表并进一步获取备案详情。

当前版本将原始 Go 库重构为更适合部署的 Node.js Web 服务形式，并在原始能力之上补充了输入校验、限流、缓存、错误脱敏、环境预检和失败冷却机制，以降低上游风控风险。

## Features

1. 基于 HTTP GET 的简单调用方式。
2. 纯 Node.js 实现，仅依赖 `sharp` 用于图像解码。
3. 内置工信部接口请求头、Cookie 会话、鉴权流程。
4. 保留滑块验证码识别能力，并将验证码选择逻辑重构为 challenge 级独立决策与显式候选排序。
5. 增加 domain 规范化与基础格式校验。
6. 增加全局、每 IP、每 domain 的文件限流与失败冷却。
7. 增加同域 singleflight 查询锁，避免缓存未命中时并发击穿上游。
8. 增加成功缓存和空结果短缓存，减少重复请求上游。
9. 错误对外脱敏，对内写入服务端日志。
10. 日志写入采用 best-effort 策略，日志失败不会破坏 API 响应。
11. 将用户可调参数迁移到独立配置文件 `config/app.js`，并支持环境变量覆盖。
12. 增加 queryByCondition 候选项诊断、标识符字段变体兼容和列表详情兜底。
13. 增加验证码候选置信度日志与可选样本落盘，便于离线比对真实 challenge。
14. 增加缓存 schema version、响应编码保护、错误分类、环境预检与基础测试骨架。
15. 增加可选 API key 鉴权，可在配置中开启并要求请求通过 `api_key` 查询参数或 `x-api-key` 请求头提供密钥。

## Project Origin

本项目并非从零实现，核心协议分析与验证码处理思路来源于原项目：

- [Mxmilu666/fuckmiit](https://github.com/Mxmilu666/fuckmiit)

本仓库在其基础上进行了以下重构：

1. 从 Go 库改造为 Node.js Web 服务。
2. 按职责拆分为 `Api`、`Captcha`、`Service`、`Http`、`Cache`、`RateLimit`、`Validation`、`Config` 等模块。
3. 增加统一的 HTTP 入口与 JSON 响应格式。
4. 增加频控、singleflight、缓存、关键字段校验、错误分类、环境预检和错误脱敏。

## Project Structure

```text
.
|- public/
|  `- index.js
|- src/
|  |- Api/
|  |  |- authApi.js
|  |  |- captchaApi.js
|  |  |- icpApi.js
|  |  `- miitClient.js
|  |- Cache/
|  |  |- fileCache.js
|  |  `- queryCache.js
|  |- Captcha/
|  |  |- captchaChallenge.js
|  |  |- captchaCore.js
|  |  |- captchaSolver.js
|  |  |- detectionCandidate.js
|  |  |- imageDecoder.js
|  |  `- rect.js
|  |- Config/
|  |  `- appConfig.js
|  |- controllers/
|  |  `- queryController.js
|  |- Exception/
|  |  `- miitException.js
|  |- Http/
|  |  `- jsonResponse.js
|  |- RateLimit/
|  |  |- domainQueryLock.js
|  |  |- fileRateLimiter.js
|  |  `- queryGuard.js
|  |- Service/
|  |  `- miitQueryService.js
|  |- Support/
|  |  |- appPaths.js
|  |  |- clientIp.js
|  |  |- debug.js
|  |  |- detailSanitizer.js
|  |  |- environmentGuard.js
|  |  |- fileLock.js
|  |  |- fileMutex.js
|  |  |- hash.js
|  |  |- logger.js
|  |  |- responseFormatter.js
|  |  `- time.js
|  |- Validation/
|  |  `- domainNormalizer.js
|  |- app.js
|  `- server.js
|- config/
|  `- app.js
|- storage/
|  |- cache/
|  |- locks/
|  |- logs/
|  `- ratelimit/
|- tests/
|  |- appConfig.test.js
|  |- captchaSolver.test.js
|  |- domainNormalizer.test.js
|  |- environmentGuard.test.js
|  |- miitQueryService.test.js
|  |- queryCache.test.js
|  |- recordNotFoundException.test.js
|  |- responseFormatter.test.js
|  `- run.js
|- package.json
`- README.md
```

## Architecture

### Request Flow

项目执行链路如下：

1. 客户端发起请求：`GET /?domain=example.com`
2. `src/app.js` 创建 HTTP server，`src/controllers/queryController.js` 处理请求
3. `EnvironmentGuard` 在入口阶段检查 `https`、`JSON` 和 Node 版本是否可用
4. `DomainNormalizer` 执行域名规范化与校验
5. `QueryCache` 优先命中成功缓存或空结果缓存
6. `DomainQueryLock` 为同一 domain 提供 singleflight 查询锁
7. 获取 domain 锁成功后再次读取缓存，避免锁等待后的重复上游查询
8. 只有真正准备访问上游时，`QueryGuard` 才执行全局、IP、domain 频控与冷却判断
9. `MiitQueryService` 执行完整查询流程
10. `AuthApi` 请求 `/auth` 获取 `Token`
11. `CaptchaApi` 请求 `/image/getCheckImagePoint` 获取验证码挑战
12. `CaptchaSolver` 会优先基于 `bigImage` 的灰色缺口检测生成候选，并辅以模板对比和近似兜底生成备选坐标；每个 challenge 只提交一个当前最优候选，如果校验失败则立即重新获取新的 challenge，再切换到下一类候选假设继续识别，避免同一个 `captchaUUID` 在首次失败后继续提交而被上游直接判定为过期
13. `IcpApi` 请求 `/icpAbbreviateInfo/queryByCondition`
14. `MiitQueryService` 对列表结果执行精确匹配，并优先选择具备有效标识符的候选项
15. 使用返回的 `mainId`、`domainId`、`serviceId` 请求详情接口；若列表项已包含完整详情字段，可在详情标识缺失或详情接口失败时回退使用列表项
16. `MiitQueryService` 对详情结果执行必填字段规范化与校验
17. `ResponseFormatter` 在真正写成功缓存前再次校验详情字段完整性
18. 成功结果进入带 schema version 的缓存并返回
19. 失败按异常类型分类，分别映射为参数错误、频控错误、存储错误、环境错误、上游错误或内部错误

### Module Responsibilities

1. `src/app.js` / `src/server.js`
   HTTP 入口，负责创建 server、路由分发、错误兜底和进程启动。

2. `src/controllers/queryController.js`
   请求处理管线，负责参数读取、缓存命中、singleflight、限流、上游查询和错误分类响应输出。

3. `src/Validation/domainNormalizer.js`
   负责域名规范化、长度限制、字符合法性和标签校验。

4. `src/Config/appConfig.js`
   负责加载 `config/app.js` 中的默认配置，并支持环境变量覆盖默认值。同时对关键整数配置做上下界夹紧，避免 0、负数或异常大值破坏运行语义。

5. `src/RateLimit/queryGuard.js`
   负责全局、IP、domain 限流和失败冷却策略。当前通过 `consumeAll()` 实现多维限流的原子消费，避免单维失败污染其他维度计数。

6. `src/RateLimit/domainQueryLock.js`
   负责同一 domain 查询过程的 singleflight 控制。

7. `src/Cache/queryCache.js`
   负责成功缓存与空结果缓存，并通过 schema version 隔离未来结构变化。

8. `src/Cache/fileCache.js`
   负责缓存文件的加锁读取、完整性校验写入和带锁轻量级过期清理。

9. `src/Api/miitClient.js`
   通用 HTTPS 客户端，维护请求头、Cookie、超时控制和上游错误截断。

10. `src/Api/authApi.js`
    封装 `auth` 接口和 `authKey` 生成逻辑，并把鉴权协议失败统一归类为上游错误。

11. `src/Api/captchaApi.js`
    封装验证码获取与校验接口，并把验证码协议失败统一归类为上游错误。

12. `src/Captcha/captchaSolver.js`
    验证码识别核心模块，负责按单个 challenge 组织灰色缺口检测、模板候选生成、近似兜底、候选排序和 challenge 失败后的重新获取，并返回实际成功的 `captchaUuid` 供后续请求头写回。图像解码使用 `sharp`。

13. `src/Api/icpApi.js`
    封装备案列表和详情查询接口。

14. `src/Service/miitQueryService.js`
    业务编排层，串起完整的 MIIT 查询流程，并补充关键字段校验、列表精确匹配、有效标识符优先选择和列表详情兜底策略。

15. `src/Support/logger.js`
    负责将详细错误和 debug 诊断信息写入本地日志，并在日志失败时降级到 `process.stderr`。

16. `src/Http/jsonResponse.js`
    负责响应输出，并在 JSON 编码失败时输出保底错误 JSON。

17. `src/Support/environmentGuard.js`
    负责运行前检查 `https` 模块、JSON 支持和 Node 版本。

18. `src/Support/responseFormatter.js`
    负责最终成功响应封装，并显式校验详情必填字段存在性。

## Requirements

运行环境要求：

1. Node.js 18 或更高版本。
2. 已安装 `sharp` 依赖（`npm install`）。
3. 运行用户需要对项目目录下的 `storage/` 有读写权限。
4. 建议保留仓库内的 `.gitignore` 和 `storage/.gitkeep` 文件，避免运行产物被误提交。
5. 如需调整缓存时长、限流阈值、等待时间等参数，优先修改 `config/app.js`，避免直接改源码逻辑。

建议在 Linux 或具备完整 Node.js 环境的服务器上运行。

## Quick Start

安装依赖并启动：

```bash
npm install
npm start
```

或直接运行：

```bash
node src/server.js
```

然后访问：

```text
http://127.0.0.1:8080/?domain=baidu.com
```

调试模式：

在配置文件中开启：

```js
debug: {
    enabled: true,
    store_captcha_samples: true,
},
```

开启后，请求：

```text
http://127.0.0.1:8080/?domain=baidu.com
```

当前版本不再支持通过 URL 参数控制 debug，是否输出调试日志只由配置文件或环境变量决定。

用户可调配置位于：

```text
config/app.js
```

当前可直接配置的内容包括：

1. `cache.schema_version`
2. `cache.success_ttl`
3. `cache.miss_ttl`
4. `ratelimit.global_qps`
5. `ratelimit.ip_per_minute`
6. `ratelimit.domain_per_window`
7. `ratelimit.domain_window_seconds`
8. `ratelimit.domain_cooldown_seconds`
9. `ratelimit.global_cooldown_seconds`
10. `ratelimit.domain_wait_timeout_seconds`
11. `ratelimit.domain_wait_interval_milliseconds`
12. `debug.enabled`
13. `debug.store_captcha_samples`
14. `auth.api_key_enabled`
15. `auth.api_key`
16. `log.max_detail_length`

若环境变量和配置文件同时存在，环境变量优先级更高。

完整配置文件示例：

```js
export default {
    cache: {
        // 用于区分缓存结构版本。调整响应结构时可修改此值，使旧缓存自动失效。
        schema_version: 'v1',

        // 成功查询结果缓存时长，单位：秒。默认 86400，即 24 小时。
        success_ttl: 86400,

        // 空结果缓存时长，单位：秒。默认 1800，即 30 分钟。
        miss_ttl: 1800,
    },

    ratelimit: {
        // 全局每秒允许进入上游查询链路的最大请求数。
        global_qps: 5,

        // 单个 IP 每分钟允许进入上游查询链路的最大请求数。
        ip_per_minute: 60,

        // 单个 domain 在指定窗口内允许进入上游查询链路的最大请求数。
        domain_per_window: 10,

        // domain 限流窗口大小，单位：秒。默认 120，即 2 分钟。
        domain_window_seconds: 120,

        // 单个 domain 在上游失败后进入冷却状态的时长，单位：秒。
        domain_cooldown_seconds: 60,

        // 全局冷却时长，单位：秒。用于上游异常后短时间减压。
        global_cooldown_seconds: 10,

        // 同一个 domain 的并发请求在等待已有查询结果时的最长等待时间，单位：秒。
        domain_wait_timeout_seconds: 3,

        // 等待期间轮询缓存的间隔，单位：毫秒。
        domain_wait_interval_milliseconds: 250,
    },

    auth: {
        // 是否开启 API key 鉴权。开启后请求必须携带 x-api-key 头。
        api_key_enabled: false,

        // API key 值，请求必须匹配此值才能通过鉴权。
        api_key: '',
    },

    debug: {
        // 是否启用调试输出。启用后服务会把流程日志写到 storage/logs 和 stderr。
        enabled: false,

        // 是否在 debug 模式下把验证码 challenge 样本落盘到 storage/debug/captcha/。
        // 开启后会额外保存 big.png、small.png 和 metadata.json，便于离线排查识别偏差。
        store_captcha_samples: false,
    },

    log: {
        // 日志详情最大截断长度。过长的上游错误会被裁剪，避免日志膨胀。
        max_detail_length: 512,
    },
};
```

配置项详细说明：

1. `cache.schema_version`
   用于控制缓存结构版本。只要这个值变化，旧缓存 key 就会自动失效。适合在响应字段调整、缓存结构变化时使用。

2. `cache.success_ttl`
   成功查询结果缓存时长，单位为秒。备案信息变更频率通常较低，默认 24 小时比较保守且能显著降低上游访问量。

3. `cache.miss_ttl`
   空结果缓存时长，单位为秒。默认 30 分钟。这个值不宜过长，否则会放大短期误判；也不宜过短，否则会反复打上游。

4. `ratelimit.global_qps`
   控制整个服务每秒最多有多少个请求进入真实上游链路。这个值越小，对上游越安全，但峰值承载能力越弱。

5. `ratelimit.ip_per_minute`
   控制单个来源 IP 在一分钟内最多触发多少次真实上游查询。适合限制恶意刷接口或误操作放量。

6. `ratelimit.domain_per_window`
   控制单个域名在限流窗口内可被查询的次数。适合防止热门 domain 或恶意 domain 被持续打上游。

7. `ratelimit.domain_window_seconds`
   指定 domain 限流窗口的长度。它和 `domain_per_window` 共同决定了单域的查询密度。

8. `ratelimit.domain_cooldown_seconds`
   单域在上游失败后进入冷却状态的时长。上游疑似风控或验证码连续失败时，这个值可以适当加大。

9. `ratelimit.global_cooldown_seconds`
   全局冷却时长。适合在上游明显异常时让整个服务短时间减速。

10. `ratelimit.domain_wait_timeout_seconds`
    singleflight 等待窗口。多个请求查询同一 domain 时，后续请求会等待已有查询写入缓存，而不是立即重复打上游。这个值过大可能占用 worker，过小则更容易直接返回 429。

11. `ratelimit.domain_wait_interval_milliseconds`
    等待期间轮询缓存的间隔。间隔越小，结果命中更及时，但轮询更频繁；间隔越大，CPU 压力更低，但返回延迟更高。

12. `debug.enabled`
    控制是否启用流程调试日志。启用后服务会将关键步骤日志写入 `storage/logs/`，并同步尝试输出到 stderr。生产环境通常建议保持 `false`，排查完成后应关闭。

13. `debug.store_captcha_samples`
    控制在 `debug.enabled=true` 时是否额外保存验证码 challenge 样本。启用后服务会把当前 challenge 的 `big.png`、`small.png` 和 `metadata.json` 写入 `storage/debug/captcha/`，用于离线比对模板匹配、图像检测和最终候选排序。默认 `false`，因为它会产生额外磁盘写入并保留调试样本。

14. `log.max_detail_length`
    控制异常详情写入日志前的最大长度。用于限制上游返回体过大时对日志系统的冲击。

15. `auth.api_key_enabled`
    控制是否开启 API key 鉴权。开启后每个请求必须在 `api_key` 查询参数或 `x-api-key` 请求头中提供密钥，值与 `auth.api_key` 匹配才能通过。查询参数和请求头同时存在时优先使用请求头。

16. `auth.api_key`
    API key 值。当 `auth.api_key_enabled` 为 `true` 时，请求的 `x-api-key` 头必须与此值完全一致。

边界行为说明：

1. 所有整型配置都会经过 `AppConfig` 的上下界夹紧，不会直接相信配置文件中的原始值。
2. 即使你在 `config/app.js` 中配置了 `0`、负数或极大值，系统仍会强制压回安全范围。
3. 因此配置文件的作用是“提供期望值”，最终运行值仍以 `AppConfig` 的边界规则为准。

环境变量覆盖说明：

1. 配置文件作为默认值来源。
2. 环境变量会覆盖配置文件中的同名项。
3. 构造函数显式传入的 overrides 优先级最高。

例如：

```text
MIIT_CACHE_SUCCESS_TTL=43200
MIIT_RATE_LIMIT_GLOBAL_QPS=5
MIIT_DEBUG_ENABLED=true
MIIT_DEBUG_STORE_CAPTCHA_SAMPLES=true
MIIT_API_KEY_ENABLED=true
MIIT_API_KEY=your-secret-key
```

这些环境变量会分别覆盖：

1. `cache.success_ttl`
2. `ratelimit.global_qps`
3. `debug.enabled`
4. `debug.store_captcha_samples`
5. `auth.api_key_enabled`
6. `auth.api_key`

推荐调整策略：

1. 单机低流量场景：
   可以适当提高 `cache.success_ttl`，降低 `global_qps`，优先保护上游。

2. 内网受控调用场景：
   可以适当提高 `ip_per_minute`，但仍建议保持较短的 `domain_cooldown_seconds`。

3. 若上游明显风控：
   优先调大：
   - `domain_cooldown_seconds`
   - `global_cooldown_seconds`
   同时降低：
   - `global_qps`
   - `domain_per_window`

4. 若并发等待过多：
   优先缩短：
   - `domain_wait_timeout_seconds`
   或增大：
   - `domain_wait_interval_milliseconds`

5. 若日志膨胀明显：
   优先降低：
   - `log.max_detail_length`

## API

### Request

Method:

```http
GET /
```

Query Parameters:

1. `domain` required
   要查询的域名。系统会自动执行规范化与格式校验。

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

API key 无效或缺失时，HTTP status: `401`（仅当开启 API key 鉴权时）。可通过 `api_key` 查询参数或 `x-api-key` 请求头提供。

```json
{
  "code": 401,
  "message": "unauthorized",
  "data": {
    "domain": "",
    "detail": "invalid or missing API key"
  }
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
2. `EnvironmentGuard` 会在入口层主动检查 `https` 模块、JSON 支持和 Node 版本，避免服务运行到中途才因环境缺陷崩溃。
3. 缓存优先于上游频控，缓存命中不会消耗上游配额。
4. 同一 domain 的并发请求先竞争 singleflight 锁，只有真正准备访问上游的请求才在锁内执行频控计数，避免“未出站先扣额度”的限流语义污染。
5. 全局、IP、domain 限流都通过 `AppConfig` 配置化，而不是硬编码在业务逻辑里，并支持通过环境变量覆盖默认值。
6. `AppConfig` 会对 TTL、QPS、等待时间、冷却时间、日志长度等整数配置做边界夹紧，防止 0、负数或异常大值导致全量拒绝、无限近似放行或 worker 长时间占用。
7. `QueryGuard` 通过 `consumeAll()` 一次性消费多维限流状态，避免 global 先扣、IP 后失败、domain 再失败时的计数污染。
8. 上游失败后会进入短暂冷却，避免连续打上游。
9. `FileRateLimiter` 的频控窗口文件和 cooldown 文件都使用文件锁保护，读取 cooldown 时也加共享锁，避免并发下漏读冷却状态。
10. `consumeAll()` 在多文件加锁过程中使用单独的已加锁句柄列表，即使中途打开或加锁失败，也会在 `finally` 中释放已持有的锁，避免锁泄漏。
11. `FileCache` 读取缓存时使用共享锁，写入缓存时使用独占锁，并校验编码、截断、写入长度和 flush 完整性，避免并发读写读到半写入内容。
12. `FileCache` 和 `FileRateLimiter` 都带有轻量级随机 GC，用于删除过期缓存、过期 cooldown 和陈旧限流窗口文件；GC 只在拿到非阻塞独占锁后才会清理文件，避免并发删除其他请求正在使用的状态文件。
13. 同域请求在拿到 domain 锁后会再次读取 success/miss cache，降低锁等待期间的重复上游访问。
14. 同域等待窗口不再固定为 2 秒，而是通过配置化的超时和轮询间隔控制；当前默认值已收紧为更保守的等待窗口，以减少 worker 长时间占用。
15. 成功结果默认缓存 24 小时。
16. 无备案记录默认缓存 30 分钟，但只有“列表接口成功且真正无记录”的 `404` 才默认写入 miss cache；精确匹配失败产生的 `404` 已标记为不可缓存，避免字段名或上游格式差异放大为持续的错误缓存。
17. 缓存条目携带 `_schema_version`，未来响应结构变化时可以通过版本变更使旧缓存自动失效。
18. 验证码求解会优先复用灰色缺口检测这条低成本主路径，并在检测结果明显异常时回退到模板对比与近似估算生成的新候选，减少 `left=0` 一类异常检测值长期主导整条链路的问题。
19. 由于当前上游在同一个 `captchaUUID` 首次校验失败后通常会直接把后续提交判定为过期，`CaptchaSolver` 现在改为每个 challenge 只提交一个候选；一旦失败，立即获取新的 challenge，并轮换到下一类候选假设继续尝试。
20. 验证码求解成功后，业务层会写回实际成功 challenge 对应的 `Uuid` 和 `Sign`，避免内部重试获取新 challenge 后 header 仍沿用第一次 uuid 的状态错配问题。
21. `MiitQueryService` 对列表结果不再机械相信第一条，而是优先寻找与查询 domain 精确匹配且具备有效标识符的项；找不到精确匹配时返回 `404`，避免错误主体写入成功缓存。
22. `MiitQueryService` 会兼容常见标识符字段变体，例如 `mainID`、`main_id`、`ids.mainId`；如果上游列表项已经包含完整详情字段，则可在标识符缺失或详情接口异常时使用列表项兜底。
23. 错误被分成参数错误、频控错误、存储错误、环境错误、上游错误和内部错误，不再把所有异常粗暴归类为上游失败。
24. `AuthApi`、`CaptchaApi`、`MiitClient` 和 `MiitQueryService` 中的上游协议错误统一升级为 `UpstreamException`，保证冷却策略只针对真正的上游故障触发。
25. 验证码识别失败、图片解码失败、`checkImage` 偏移尝试耗尽等路径也统一归类为 `UpstreamException`，不再错误落入内部错误分支。
26. `MiitClient` 会截断写入日志的上游错误详情，防止异常响应体无限放大日志体积。
27. `DetailSanitizer` 做字符串截断，防止异常响应体无限放大日志体积。
28. `Logger` 采用 best-effort 策略，日志目录不可写时会降级尝试写入 `process.stderr`，不会再向外抛异常。
29. `Debug` 会把流程诊断写入结构化日志，并同步尝试写入 stderr；HTTP 响应仍保持错误脱敏，不会因为开启 debug 而暴露内部异常。
30. `JsonResponse` 会检查 `JSON.stringify()` 结果，编码失败时输出保底 JSON，并同步把 HTTP 状态修正为 `500`，避免 HTTP 状态和 JSON body code 矛盾。
31. `ResponseFormatter` 会校验必填详情字段，不再用空字符串静默吞掉字段缺失；同时先格式化、后写成功缓存，避免不可渲染数据进入 success cache。
32. storage 目录在运行时会校验可创建、可写，避免限流与缓存静默失效。
33. 初始化阶段异常也会进入统一 JSON 错误出口，避免 API 返回非 JSON 错误页。

## Implementation Notes

为了尽可能保持与原项目一致，当前 Node.js 版本沿用了原始实现的几个核心策略：

1. 使用固定请求头模拟浏览器环境。
2. 使用 Cookie 持续维持服务端会话状态。
3. 使用 `md5("testtest" + timestamp)` 构造 `authKey`。
4. 使用验证码大图中的缺口区域进行本地识别。
5. 验证码候选横坐标会优先来自 `bigImage` 中灰色缺口块的本地检测，并辅以模板对比和 `estimate` 近似兜底生成备选坐标；每个 challenge 只提交一个当前最优候选，如果失败则重新获取新的验证码并切换到下一类候选继续尝试，以规避上游对同一 challenge 二次提交直接判定过期的行为。
6. 列表查询后优先进行精确匹配，并优先选择具备有效详情标识符的候选项，而不是盲目回退第一条结果。
7. 只有在列表接口本身成功且结果为空，或精确匹配失败时，才返回 `404`；其中精确匹配失败默认不会写入 miss cache。
8. 如果列表候选项已经包含完整成功响应所需字段，详情标识符缺失或详情接口异常时可以回退使用该列表项。
9. 上游异常、签名失效、鉴权失败、风控等情况统一落到 `UpstreamException` 路径，而本地存储与环境问题会走不同分类。
10. 入口层的组件初始化、环境预检、缓存、锁、限流和查询都走统一异常出口。
11. 日志系统是辅助能力，失败时不会反向影响主响应契约。
12. 调试输出默认关闭，是否启用只由配置文件或环境变量控制，不再接受 URL 参数切换。
13. 当前 `EnvironmentGuardTest` 会真实调用 `EnvironmentGuard.assertRuntimeReady()`，根据当前环境中的 `https` 模块和 Node 版本断言预检行为。

## Storage

项目会在运行时自动创建 `storage/` 目录，用于：

1. `storage/cache/`
   保存成功缓存和空结果缓存。

2. `storage/ratelimit/`
   保存频控窗口与冷却状态。

3. `storage/locks/`
   保存 singleflight 锁文件。

4. `storage/logs/`
   保存结构化错误日志和 debug 诊断日志。

5. `storage/debug/captcha/`
   当同时开启 `debug.enabled=true` 和 `debug.store_captcha_samples=true` 时，保存验证码 challenge 的调试样本，包括 `big.png`、`small.png` 和 `metadata.json`。

这些文件都是本地文件实现，适合单机部署。如果你在多实例环境中运行，建议后续替换为 Redis 等共享存储。

项目默认通过 `.gitignore` 忽略 `storage/` 下的运行产物，并使用 `.gitkeep` 保留必要目录结构。

测试目录中的缓存版本测试会写入 `storage/test-cache/`，并在测试结束后主动清理该目录中的文件和目录本身，降低测试对运行目录的污染。

## Limitations

该项目本质上仍然依赖目标站点当前的协议和验证码样式，因此存在天然脆弱性。

主要限制包括：

1. 如果工信部接口字段发生变化，服务可能失效。
2. 如果验证码颜色、形状或返回数据结构改变，识别逻辑可能失效。
3. 当前缓存、锁和频控基于本地文件，适合单机，不适合直接横向扩容。
4. 列表结果虽然增加了精确匹配、有效标识符优先和列表详情兜底，但仍受上游字段质量影响。
5. 当前没有 stale 数据回退策略，500 时不会回放历史成功缓存。
6. 同域 singleflight 当前是等待后回读缓存的模式，不是长轮询队列或作业系统。
7. 仓库附带了 `package.json` 和基础测试骨架，测试使用 Node 内置 `node:test` 运行。
8. 当前测试已覆盖域名规范化、环境预检行为、缓存版本、响应字段完整性、配置边界、`404` 可缓存标志、列表候选项选择、标识符字段变体、列表详情兜底，以及验证码偏移展开顺序规则，但仍不足以替代完整的并发、锁竞争和真实上游集成测试。

这意味着该项目更适合作为特定场景下的工程化工具，而非长期稳定的官方兼容方案。

## Debugging

当配置文件中的 `debug.enabled=true` 时，服务会把流程日志写入 `storage/logs/app-YYYY-MM-DD.log`，并同步尝试输出到 stderr。HTTP 响应仍然保持脱敏，不会因为开启 debug 而把内部异常直接返回给浏览器。

常见流程日志包括：

1. `step=auth`
2. `step=getCheckImagePoint`
3. `step=detect method=...`
4. `step=checkImage attempt_left=...`
5. `step=checkImage rejected`
6. `step=getCheckImagePoint retry`
7. `step=query`
8. `step=queryByCondition success=true`
9. `step=queryByCondition selected_match`
10. `step=queryByCondition exact_matches_without_valid_identifiers`
11. `step=queryByCondition missing_valid_identifiers`
12. `step=queryDetail`
13. `step=queryByCondition fallback=list_item_detail`
14. `step=queryDetail fallback=list_item_detail`

`queryByCondition` 相关 debug 日志会记录列表数量、候选项 key、原始标识符值和归一化后的标识符，用于排查上游字段变更、列表项缺少 `mainId` / `domainId` / `serviceId`、详情接口不可用等问题。

验证码相关 debug 日志会记录本轮选中的检测方法、候选坐标、候选排序、当前验证码尝试序号，以及每次 `checkImage` 拒绝时的上游返回码和消息，用于排查灰色缺口检测失效、模板候选偏差、`left=0` 异常值主导和验证码窗口过期等问题。

如果同时开启 `debug.enabled=true` 和 `debug.store_captcha_samples=true`，服务会把当前 challenge 的 `big.png`、`small.png` 和 `metadata.json` 落盘到 `storage/debug/captcha/`，用于离线比对真实 challenge。

服务端详细错误和 debug 诊断都会写入 `storage/logs/`，用于排查验证码识别失败、接口返回异常、频控触发、上游风控和列表字段结构变化问题。

基础测试骨架可在具备 Node.js 环境的情况下运行：

```bash
node tests/run.js
```
