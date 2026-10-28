# GitHub 图片直链上传 + 文字背景合成 + 二维码工具

目标仓库：`kuniuer/mcp-transfer-station`，分支：`main`。

## 网页功能

- 上传背景图，或清除背景图后使用纯色底。
- 输入文字并叠加在背景上方；支持独立字号、行高、内边距、文字颜色和描边。
- 独立设置画布宽高，或沿用背景图原始尺寸。
- 修改文字、背景或样式时自动刷新合成预览。
- 保存合成 PNG；上传成功后自动回填 GitHub Raw HTTPS 图片直链并生成二维码。
- 保留二维码尺寸、纠错等级、复制直链和二维码 PNG 下载。

## 重要：前端与 PHP 上传接口

GitHub Pages 只托管静态文件，不会执行 PHP。网页默认使用同目录相对地址 `./github_upload.php`，因此需要把 `index.html` 和 `github_upload.php` 一起部署到支持 PHP 的 HTTPS 服务器（例如已配置 HTTPS 的宝塔站点）。如果网页与接口分属不同域名，请在网页的“上传接口地址”中填写实际的 HTTPS 接口地址，并在服务端配置 `UPLOAD_ALLOWED_ORIGIN`。

仓库中提供 `github_upload.php` 作为安全上传代理源码。当前没有自动部署到宝塔服务器；需要把它上传到服务器网站目录，并在 PHP-FPM/站点环境中设置以下变量：

- `GITHUB_TOKEN`：GitHub Fine-grained PAT，仅授权 `kuniuer/mcp-transfer-station` 仓库的 Contents 写入权限。
- `UPLOAD_KEY`：单独生成的长随机密钥；在网页上传区域输入此密钥。它不是 GitHub Token。
- `GITHUB_REPO=kuniuer/mcp-transfer-station`
- `GITHUB_BRANCH=main`
- `MAX_UPLOAD_BYTES=6291456`（可选，默认 6 MiB）
- `UPLOAD_ALLOWED_ORIGIN`（可选；网页和 PHP 不同源时填写网页的完整 origin，例如 `https://example.com`）
- `UPLOAD_LOG_FILE`（可选，建议指向网站公开目录之外的日志文件）

`.env.example` 仅为配置模板；PHP 不会自动读取它。请将变量配置在服务器/PHP-FPM 环境中，切勿把真实 Token 或上传密钥写进 HTML 或提交到 GitHub。服务器需要 PHP `curl` 与 `fileinfo` 扩展，并允许 HTTPS 访问 `api.github.com`。

## 上传接口行为

- 仅接受 `POST`，表单文件字段名为 `file`，并要求 `X-Upload-Key` 请求头。
- 校验上传大小、文件真实 MIME 类型和图片尺寸。
- 只允许 PNG、JPG/JPEG、WebP、GIF；每次上传使用独立文件名，存放于 `uploads/YYYY/MM/`。
- 成功返回 JSON：`success`、`filename`、`path`、`imageUrl`、`message`。
- 上传日志默认写入系统临时目录的 `github-image-upload-log.jsonl`；可用 `UPLOAD_LOG_FILE` 指定非公开路径。

## 当前部署状态

源码已准备在仓库中，但没有已知的宝塔站点域名、PHP 上传接口地址或服务器环境权限，因此尚不能声称接口已经在线部署。部署完成后，在网页中默认的 `./github_upload.php` 才能工作；若站点不在同源目录，需要填写实际 HTTPS 地址。不要在公开仓库中放入真实密钥。