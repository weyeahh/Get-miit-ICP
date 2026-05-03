# Get MIIT ICP

一个基于 Node.js 的 MIIT 备案查询服务，接收 `GET` 请求中的 `domain` 参数，返回工信部备案详细信息，适配了最新版工信部查询系统的滑块验证。

部分思路来源于 [Mxmilu666/fuckmiit](https://github.com/Mxmilu666/fuckmiit)，本项目在此基础上进行了全面重构和扩展，补充了输入校验、限流、缓存、错误脱敏、环境预检和失败冷却等机制，以降低上游风控风险。

## Features

1. 基于 HTTP GET 的简单调用方式。
2. 纯 Node.js 实现，仅依赖 `sharp` 用于图像解码。
3. 内置工信部接口请求头、Cookie 会话、鉴权流程。
4. 保留滑块验证码识别能力，并将验证码选择逻辑重构为 challenge 级独立决策与显式候选排序。
5. 增加 domain 规范化与基础格式校验。
6. 增加全局、每 IP、每 domain 的文件限流与失败冷却；可选切换为 Redis 后端，支持分布式限流、缓存和互斥锁。
7. 增加同域 singleflight 查询锁，避免缓存未命中时并发击穿上游。
8. 增加成功缓存和空结果短缓存，减少重复请求上游。
9. 错误对外脱敏，对内写入服务端日志。
10. 日志写入采用 best-effort 策略，日志失败不会破坏 API 响应。
11. 将用户可调参数迁移到内置默认配置，支持通过 `.env` 环境变量覆盖。
12. 增加 queryByCondition 候选项诊断、标识符字段变体兼容和列表详情兜底。
13. 增加验证码候选置信度日志与可选样本落盘，便于离线比对真实 challenge。
14. 增加缓存 schema version、响应编码保护、错误分类、环境预检与基础测试骨架。
15. 增加可选 API key 鉴权，可在配置中开启并要求请求通过 `api_key` 查询参数或 `x-api-key` 请求头提供密钥。

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
|- .env.example
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
10. `AuthApi` 请求 `/auth` 获取 `Token`（含指数退避重试）
11. `CaptchaApi` 请求 `/image/getCheckImagePoint` 获取验证码挑战（含指数退避重试）
12. `CaptchaSolver` 会优先基于 `bigImage` 的灰色缺口检测生成候选，并辅以模板对比和近似兜底生成备选坐标；每个 challenge 只提交一个当前最优候选，如果校验失败则立即重新获取新的 challenge，再切换到下一类候选假设继续识别，避免同一个 `captchaUUID` 在首次失败后继续提交而被上游直接判定为过期
13. `IcpApi` 请求 `/icpAbbreviateInfo/queryByCondition` 和 `queryDetail`（含指数退避重试）
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
   负责加载内置默认配置，并支持通过 `.env` 环境变量覆盖默认值。同时对关键整数配置做上下界夹紧，避免 0、负数或异常大值破坏运行语义。

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
13. `src/Captcha/captchaCore.js`
    验证码底层算法模块，提供连通域 flood-fill、方块判定、缺口颜色自适应采样、多组预置色域回退轮询和窗口评分等纯函数。

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

19. `src/Storage/redisBackend.js`
    Redis 存储后端实现，包含 `RedisCache`（带逻辑过期的双层 TTL 缓存）、`RedisRateLimiter`（基于 Lua 脚本的固定窗口原子限流）和 `RedisLockProvider`（带 watchdog 续期的分布式互斥锁）。Redis 客户端使用 Promise 单例模式避免并发初始化竞态，并注册 `error` 事件监听防止未捕获异常。通过 `closeRedisClient()` 支持优雅关闭。

## Requirements

运行环境要求：

1. Node.js 18 或更高版本。
2. 已安装 `sharp` 依赖（`npm install`）。
3. 运行用户需要对项目目录下的 `storage/` 有读写权限。
4. 建议保留仓库内的 `.gitignore` 和 `storage/.gitkeep` 文件，避免运行产物被误提交。
5. 如需调整缓存时长、限流阈值、等待时间等参数，通过 `.env` 环境变量设置，避免直接改源码逻辑。

建议在 Linux 或具备完整 Node.js 环境的服务器上运行。

## Quick Start

```bash
git clone https://github.com/weyeahh/Get-miit-ICP.git
cd Get-miit-ICP
cp .env.example .env
# 编辑 .env 按需修改配置
npm install
npm start
```

> 编辑监听地址、端口、调试开关或切换存储后端等，均可通过 `.env` 设置。若未创建 `.env`，服务将使用内置默认值运行。

启动后访问：

```text
http://127.0.0.1:8080/?domain=baidu.com
```

## Configuration

所有配置项通过环境变量设置，推荐使用 `.env` 文件。将 `.env.example` 复制为 `.env` 并按需修改即可。

未设置环境变量时，服务使用代码内置默认值。所有整型配置都会经过 `AppConfig` 的上下界夹紧，不会直接相信原始值（`0`、负数或极大值会被强制压回安全范围）。

### 环境变量列表

| 变量名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `HOST` | string | `127.0.0.1` | 服务监听地址 |
| `PORT` | integer | `8080` | 服务监听端口 |
| `MIIT_CACHE_SCHEMA_VERSION` | string | `v1` | 缓存结构版本，修改后旧缓存自动失效 |
| `MIIT_CACHE_SUCCESS_TTL` | integer | `86400` | 成功查询结果缓存时长（秒），范围 60–604800 |
| `MIIT_CACHE_MISS_TTL` | integer | `1800` | 空结果缓存时长（秒），范围 30–86400 |
| `MIIT_CACHE_SUCCESS_STALE_TTL` | integer | `604800` | 成功结果 Redis 物理保留时长（秒），仅 `redis` 后端生效，范围 300–2592000 |
| `MIIT_CACHE_MISS_STALE_TTL` | integer | `86400` | 空结果 Redis 物理保留时长（秒），仅 `redis` 后端生效，范围 60–604800 |
| `MIIT_RATE_LIMIT_GLOBAL_QPS` | integer | `5` | 全局每秒最大上游查询数，范围 1–1000 |
| `MIIT_RATE_LIMIT_IP_PER_MINUTE` | integer | `60` | 单 IP 每分钟最大上游查询数，范围 1–10000 |
| `MIIT_RATE_LIMIT_DOMAIN_PER_WINDOW` | integer | `10` | 单 domain 每窗口最大查询数，范围 1–1000 |
| `MIIT_RATE_LIMIT_DOMAIN_WINDOW_SECONDS` | integer | `120` | domain 限流窗口大小（秒），范围 1–86400 |
| `MIIT_RATE_LIMIT_DOMAIN_COOLDOWN_SECONDS` | integer | `60` | 单 domain 上游失败后冷却时长（秒），范围 1–3600 |
| `MIIT_RATE_LIMIT_GLOBAL_COOLDOWN_SECONDS` | integer | `10` | 全局冷却时长（秒），范围 1–3600 |
| `MIIT_RATE_LIMIT_DOMAIN_WAIT_TIMEOUT_SECONDS` | integer | `3` | singleflight 等待窗口（秒），范围 0–10 |
| `MIIT_RATE_LIMIT_DOMAIN_WAIT_INTERVAL_MILLISECONDS` | integer | `250` | singleflight 等待期间缓存轮询间隔（毫秒），范围 10–1000 |
| `MIIT_API_KEY_ENABLED` | boolean | `false` | 是否开启 API key 鉴权 |
| `MIIT_API_KEY` | string | 无 | API key 值，开启鉴权时必填 |
| `MIIT_DEBUG_ENABLED` | boolean | `false` | 是否启用流程调试日志 |
| `MIIT_DEBUG_STORE_CAPTCHA_SAMPLES` | boolean | `false` | 是否保存验证码 challenge 样本到磁盘，仅 `debug.enabled=true` 时生效 |
| `MIIT_LOG_MAX_DETAIL_LENGTH` | integer | `512` | 日志详情最大截断长度，范围 64–4096 |
| `MIIT_STORAGE_BACKEND` | string | `file` | 存储后端，可选 `file` 或 `redis` |
| `MIIT_STORAGE_REDIS_URL` | string | `redis://127.0.0.1:6379` | Redis 连接地址 |
| `MIIT_STORAGE_REDIS_KEY_PREFIX` | string | `miit:` | Redis key 前缀，用于命名空间隔离 |
| `MIIT_STORAGE_REDIS_CONNECT_TIMEOUT` | integer | `3000` | Redis 连接超时（毫秒） |

### .env 示例

参见项目根目录下的 `.env.example` 文件，也可直接参考以下内容：

```bash
# 复制此文件为 .env 并按需修改
# 所有变量均为可选，未设置时使用代码内置默认值

# 服务监听
HOST=127.0.0.1
PORT=8080

# 缓存
MIIT_CACHE_SCHEMA_VERSION=v1
MIIT_CACHE_SUCCESS_TTL=86400
MIIT_CACHE_MISS_TTL=1800
MIIT_CACHE_SUCCESS_STALE_TTL=604800
MIIT_CACHE_MISS_STALE_TTL=86400

# 限流
MIIT_RATE_LIMIT_GLOBAL_QPS=5
MIIT_RATE_LIMIT_IP_PER_MINUTE=60
MIIT_RATE_LIMIT_DOMAIN_PER_WINDOW=10
MIIT_RATE_LIMIT_DOMAIN_WINDOW_SECONDS=120
MIIT_RATE_LIMIT_DOMAIN_COOLDOWN_SECONDS=60
MIIT_RATE_LIMIT_GLOBAL_COOLDOWN_SECONDS=10
MIIT_RATE_LIMIT_DOMAIN_WAIT_TIMEOUT_SECONDS=3
MIIT_RATE_LIMIT_DOMAIN_WAIT_INTERVAL_MILLISECONDS=250

# 鉴权
MIIT_API_KEY_ENABLED=false
MIIT_API_KEY=

# 调试
MIIT_DEBUG_ENABLED=false
MIIT_DEBUG_STORE_CAPTCHA_SAMPLES=false

# 日志
MIIT_LOG_MAX_DETAIL_LENGTH=512

# 存储
MIIT_STORAGE_BACKEND=file
MIIT_STORAGE_REDIS_URL=redis://127.0.0.1:6379
MIIT_STORAGE_REDIS_KEY_PREFIX=miit:
MIIT_STORAGE_REDIS_CONNECT_TIMEOUT=3000
```

### 推荐调整策略

1. **单机低流量场景**：适当提高 `MIIT_CACHE_SUCCESS_TTL`，降低 `MIIT_RATE_LIMIT_GLOBAL_QPS`，优先保护上游。
2. **内网受控调用场景**：适当提高 `MIIT_RATE_LIMIT_IP_PER_MINUTE`，保持较短 `MIIT_RATE_LIMIT_DOMAIN_COOLDOWN_SECONDS`。
3. **上游明显风控**：调大 `MIIT_RATE_LIMIT_DOMAIN_COOLDOWN_SECONDS` 和 `MIIT_RATE_LIMIT_GLOBAL_COOLDOWN_SECONDS`，同时降低 `MIIT_RATE_LIMIT_GLOBAL_QPS` 和 `MIIT_RATE_LIMIT_DOMAIN_PER_WINDOW`。
4. **并发等待过多**：缩短 `MIIT_RATE_LIMIT_DOMAIN_WAIT_TIMEOUT_SECONDS` 或增大 `MIIT_RATE_LIMIT_DOMAIN_WAIT_INTERVAL_MILLISECONDS`。
5. **日志膨胀明显**：降低 `MIIT_LOG_MAX_DETAIL_LENGTH`。

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

实时查询（`cache` 为 `miss`）：

```json
{
  "code": 200,
  "message": "successful",
  "cache": "miss",
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

命中缓存（`cache` 为 `hit`），额外返回 `cached_at` 表示缓存写入时间：

```json
{
  "code": 200,
  "message": "successful",
  "cache": "hit",
  "cached_at": "2026-05-03T12:00:00.000Z",
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

`cache` 字段位于响应顶层（与 `code`、`message`、`data` 同级），值为 `hit` 表示命中缓存，`miss` 表示实时查询。当 `cache` 为 `hit` 时，同级还会返回 `cached_at`（ISO 8601 格式），表示该缓存条目被写入的时间。

## Governance Strategy

为了降低上游风控风险，当前版本增加了治理层：

1. domain 参数进入主链路前会做规范化与格式校验。
2. `EnvironmentGuard` 会在入口层主动检查 `https` 模块、JSON 支持、Node 版本（>=18.18）和 `sharp` 可用性，避免服务运行到中途才因环境缺陷崩溃。
3. 缓存优先于上游频控，缓存命中不会消耗上游配额。
4. 同一 domain 的并发请求先竞争 singleflight 锁，只有真正准备访问上游的请求才在锁内执行频控计数，避免“未出站先扣额度”的限流语义污染。
5. 全局、IP、domain 限流都通过 `AppConfig` 配置化，而不是硬编码在业务逻辑里，并支持通过环境变量覆盖默认值。
6. `AppConfig` 会对 TTL、QPS、等待时间、冷却时间、日志长度等整数配置做边界夹紧，防止 0、负数或异常大值导致全量拒绝、无限近似放行或 worker 长时间占用。
7. `QueryGuard` 通过 `consumeAll()` 一次性消费多维限流状态，避免 global 先扣、IP 后失败、domain 再失败时的计数污染。
8. 上游失败后会进入短暂冷却，避免连续打上游。
9. `FileRateLimiter` 的频控窗口文件和 cooldown 文件使用 `mkdir` 原子文件锁保护写入，读取 cooldown 时也加锁避免 TOCTOU 竞争。
10. `consumeAll()` 在多文件加锁过程中使用单独的已加锁句柄列表，即使中途打开或加锁失败，也会在 `finally` 中释放已持有的锁，避免锁泄漏。
11. `FileCache` 读取缓存时使用共享锁，写入缓存时使用独占锁，并校验编码、截断、写入长度和 flush 完整性，避免并发读写读到半写入内容。
12. `FileCache` 和 `FileRateLimiter` 都带有轻量级随机 GC，用于删除过期缓存、过期 cooldown 和陈旧限流窗口文件；GC 只在拿到非阻塞独占锁后才会清理文件，避免并发删除其他请求正在使用的状态文件。
13. 同域请求在拿到 domain 锁后会再次读取 success/miss cache，降低锁等待期间的重复上游访问。
14. 同域等待窗口不再固定为 2 秒，而是通过配置化的超时和轮询间隔控制；当前默认值已收紧为更保守的等待窗口，以减少 worker 长时间占用。
15. 成功结果默认缓存 24 小时。
16. 无备案记录默认缓存 30 分钟，但只有“列表接口成功且真正无记录”的 `404` 才默认写入 miss cache；精确匹配失败产生的 `404` 已标记为不可缓存，避免字段名或上游格式差异放大为持续的错误缓存。
17. 缓存条目携带 `_schema_version`，未来响应结构变化时可以通过版本变更使旧缓存自动失效。
18. 验证码求解会优先从 `bigImage` 的 `topHint` 区域自适应采样实际缺口背景色，并配置 4 组预置颜色回退轮询；同时将模板搜索改为两步粗精扫描（粗搜 step=12 选 top-3 → 精搜 ±12px step=3），降低事件循环阻塞时间。
19. 由于当前上游在同一个 `captchaUUID` 首次校验失败后通常会直接把后续提交判定为过期，`CaptchaSolver` 现在改为每个 challenge 只提交一个候选；一旦失败，立即获取新的 challenge，并轮换到下一类候选假设继续尝试。
20. 验证码求解成功后，业务层会写回实际成功 challenge 对应的 `Uuid` 和 `Sign`，避免内部重试获取新 challenge 后 header 仍沿用第一次 uuid 的状态错配问题。
21. 上游接口调用（auth、getCheckImagePoint、queryByCondition、queryDetail）均带指数退避重试（500ms → 1000ms → 2000ms，最多 3 次），应对工信部服务端的临时性波动。
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

当前版本沿用了以下几个核心策略：

1. 使用固定请求头模拟浏览器环境。
2. 使用 Cookie 持续维持服务端会话状态。
3. 使用 `md5("testtest" + timestamp)` 构造 `authKey`。
4. 使用验证码大图中的缺口区域进行本地识别。
5. 验证码候选横坐标会优先来自 `bigImage` 中灰色缺口块的本地检测，并辅以模板对比和 `estimate` 近似兜底生成备选坐标；缺口颜色通过自适应采样 + 4 组预置色域回退轮询确定，不再依赖单一硬编码颜色；模板匹配使用两步粗精搜索降量约 90%。每个 challenge 只提交一个当前最优候选，如果失败则重新获取新的验证码并切换到下一类候选继续尝试，以规避上游对同一 challenge 二次提交直接判定过期的行为。
6. 列表查询后优先进行精确匹配，并优先选择具备有效详情标识符的候选项，而不是盲目回退第一条结果。
7. 只有在列表接口本身成功且结果为空，或精确匹配失败时，才返回 `404`；其中精确匹配失败默认不会写入 miss cache。
8. 如果列表候选项已经包含完整成功响应所需字段，详情标识符缺失或详情接口异常时可以回退使用该列表项。
9. 上游异常、签名失效、鉴权失败、风控等情况统一落到 `UpstreamException` 路径，而本地存储与环境问题会走不同分类；上游调用均带指数退避重试，应对临时性波动。
10. 入口层的组件初始化、环境预检、缓存、锁、限流和查询都走统一异常出口。
11. 日志系统是辅助能力，失败时不会反向影响主响应契约。
12. 调试输出默认关闭，是否启用只由配置文件或环境变量控制，不再接受 URL 参数切换。
13. 当前 `EnvironmentGuardTest` 会真实调用 `EnvironmentGuard.assertRuntimeReady()`，根据当前环境中的 `https` 模块和 Node 版本断言预检行为。
14. 服务支持 SIGTERM / SIGINT 优雅关闭，关闭时会等待 HTTP 连接排空并断开 Redis 连接；日志按日期轮转并自动清理 7 天前的文件；`storage/debug/captcha/` 最多保留 20 个样本目录，防止磁盘无限增长。

15. 请求处理管线中的 `AppConfig`、`CacheStore`、`RateLimiter`、`LockProvider` 使用模块级懒加载单例，避免每请求重复创建对象和重复执行配置解析。`handleError` 路径复用同一 `AppConfig` 实例，不再每次新建。

16. HTTP 服务器设置了 30 秒请求超时、15 秒 header 超时和 10 秒 keep-alive 超时，防止慢客户端无限占用连接。

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

这些文件都是本地文件实现。当前默认实现适合单机部署；缓存、限流和锁模块均通过内部接口隔离，可替换为 Redis 等共享存储后端。

当 `storage.backend` 设为 `redis` 时，缓存、限流和分布式锁均切换为 Redis 实现：

- **缓存**：使用双层 TTL 策略。Redis 键的物理 TTL 为 `success_stale_ttl`（默认 7 天），逻辑过期由 `success_ttl`（默认 24 小时）控制。过期后数据仍保留在 Redis 中，上游故障时可作为 stale 降级响应返回。
- **限流**：基于 Lua 脚本实现固定窗口原子计数，窗口对齐到 `floor(now/window)*window`，与文件限流器语义一致。支持 domain、全局 QPS 和 IP 三个维度的原子多规则消费。
- **分布式锁**：使用 `SET NX PX` 实现互斥，TTL 60 秒，带 watchdog 定时续期（每 30 秒），`acquire()` 设 15 秒超时上限。释放使用 Lua 脚本保证仅释放自己持有的锁。
- **连接管理**：Redis 客户端使用 Promise 单例模式，避免并发请求重复创建连接。注册 `error` 事件监听防止连接断开时未捕获异常。服务关闭时通过 `quit()` 优雅断开连接。`enableOfflineQueue=false` 防止 Redis 不可用时命令队列无限增长。

项目默认通过 `.gitignore` 忽略 `storage/` 下的运行产物，并使用 `.gitkeep` 保留必要目录结构。

测试目录中的缓存版本测试会写入 `storage/test-cache/`，并在测试结束后主动清理该目录中的文件和目录本身，降低测试对运行目录的污染。

## Limitations

该项目本质上仍然依赖目标站点当前的协议和验证码样式，因此存在天然脆弱性。

主要限制包括：

1. 如果工信部接口字段发生变化，服务可能失效。
2. 如果验证码颜色、形状或返回数据结构改变，识别逻辑可能失效。
3. 当前缓存、锁和频控默认基于本地文件实现，锁使用 `mkdir` 原子操作保证跨进程互斥。可通过 `storage.backend=redis` 切换为 Redis 后端，支持分布式部署。
4. 列表结果虽然增加了精确匹配、有效标识符优先和列表详情兜底，但仍受上游字段质量影响。
5. 上游失败时会优先回放已过期的 stale cache 作为降级数据，标记 `stale: true`。在 Redis 模式下，通过双层 TTL 策略保证过期数据在 `success_stale_ttl` 时间窗口内仍可被降级读取。
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

验证码相关 debug 日志会记录本轮选中的检测方法、候选坐标、候选排序、当前验证码尝试序号、是否使用自适应颜色采样（`sampled: true`），以及每次 `checkImage` 拒绝时的上游返回码和消息，用于排查灰色缺口检测失效、模板候选偏差、`left=0` 异常值主导和验证码窗口过期等问题。

如果同时开启 `debug.enabled=true` 和 `debug.store_captcha_samples=true`，服务会把当前 challenge 的 `big.png`、`small.png` 和 `metadata.json` 落盘到 `storage/debug/captcha/`，用于离线比对真实 challenge。

服务端详细错误和 debug 诊断都会写入 `storage/logs/`，用于排查验证码识别失败、接口返回异常、频控触发、上游风控和列表字段结构变化问题。

基础测试骨架可在具备 Node.js 环境的情况下运行：

```bash
node tests/run.js
```
