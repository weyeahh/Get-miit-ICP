# PHP MIIT Query

这是对 `fuckmiit` 的 PHP 重写版本，目标是通过 HTTP GET 传入 `domain` 参数并返回备案详细信息。

## 目录

1. `public/index.php`：Web 入口
2. `src/Api`：工信部接口封装
3. `src/Captcha`：验证码求解逻辑
4. `src/Service`：完整查询服务

## 用法

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

## 依赖要求

1. PHP 8.1+
2. `curl` 扩展
3. `gd` 扩展

## 响应格式

成功：

```json
{
  "success": true,
  "domain": "baidu.com",
  "data": {
    "domain": "baidu.com"
  }
}
```

失败：

```json
{
  "success": false,
  "error": "..."
}
```
