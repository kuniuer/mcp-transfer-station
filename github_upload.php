<?php
declare(strict_types=1);

/* Secure upload proxy. Required env: GITHUB_TOKEN, UPLOAD_KEY. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function env_value(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : trim((string)$value);
}
function log_upload(array $entry): void {
    $path = env_value('UPLOAD_LOG_FILE', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'github-image-upload-log.jsonl');
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function github_api(string $method, string $url, string $token, ?array $payload = null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('服务器 PHP 未启用 cURL 扩展。');
    $headers = ['Accept: application/vnd.github+json','Authorization: Bearer ' . $token,'X-GitHub-Api-Version: 2022-11-28','User-Agent: kuniuer-github-image-upload'];
    if ($payload !== null) $headers[] = 'Content-Type: application/json; charset=utf-8';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $body = curl_exec($ch); $curlError = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body === false) throw new RuntimeException('连接 GitHub API 失败：' . ($curlError ?: '网络错误') . '。请检查服务器出站网络后重试。');
    $decoded = json_decode((string)$body, true);
    return ['status'=>$status,'body'=>is_array($decoded)?$decoded:[]];
}
function api_error_message(int $status, array $body): string {
    $detail = isset($body['message']) && is_string($body['message']) ? $body['message'] : '';
    if ($status === 401) return 'GitHub Token 无效或已过期，请检查 GITHUB_TOKEN。';
    if ($status === 403) return 'GitHub 拒绝请求：可能是 Contents 写入权限不足或 API 限流。' . ($detail ? ' '.$detail : '');
    if ($status === 404) return '仓库不存在或 Token 无权访问，请检查 GITHUB_REPO 和 Token 授权范围。';
    if ($status === 409 || $status === 422) return 'GitHub 拒绝创建文件（HTTP '.$status.'）。请检查仓库、分支及权限。' . ($detail ? ' '.$detail : '');
    if ($status >= 500) return 'GitHub API 暂时异常（HTTP '.$status.'），请稍后重试。';
    return 'GitHub API 请求失败（HTTP '.$status.'）。' . ($detail ? ' '.$detail : '');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $allowedOrigin = env_value('UPLOAD_ALLOWED_ORIGIN');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($allowedOrigin !== '' && hash_equals($allowedOrigin, $origin)) {
        header('Access-Control-Allow-Origin: '.$allowedOrigin); header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type, X-Upload-Key');
    }
    http_response_code(204); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS'); respond(405, ['success'=>false,'message'=>'只允许 POST 上传请求。']);
}
$allowedOrigin = env_value('UPLOAD_ALLOWED_ORIGIN');
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($allowedOrigin !== '') {
    if ($origin !== '' && !hash_equals($allowedOrigin, $origin)) respond(403, ['success'=>false,'message'=>'请求来源不在服务器允许列表中。']);
    if ($origin !== '') { header('Access-Control-Allow-Origin: '.$allowedOrigin); header('Vary: Origin'); }
}
$token = env_value('GITHUB_TOKEN');
$expectedKey = env_value('UPLOAD_KEY');
$repo = env_value('GITHUB_REPO', 'kuniuer/mcp-transfer-station');
$branch = env_value('GITHUB_BRANCH', 'main');
$maxBytes = max(1, min((int)env_value('MAX_UPLOAD_BYTES', '6291456'), 25 * 1024 * 1024));
if ($token === '' || $expectedKey === '') respond(503, ['success'=>false,'message'=>'服务器尚未配置 GITHUB_TOKEN 或 UPLOAD_KEY。']);
if (!preg_match('/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/', $repo)) respond(500, ['success'=>false,'message'=>'GITHUB_REPO 配置格式错误。']);
$providedKey = (string)($_SERVER['HTTP_X_UPLOAD_KEY'] ?? '');
if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) respond(401, ['success'=>false,'message'=>'上传接口密钥不正确。']);
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) respond(400, ['success'=>false,'message'=>'未收到图片文件，请确认表单字段名为 file。']);
$file = $_FILES['file'];
$error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    $message = ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE)
        ? '图片超过 PHP 上传限制，请调高 upload_max_filesize 和 post_max_size。'
        : '接收图片失败（上传错误码 '.$error.'）。';
    respond(400, ['success'=>false,'message'=>$message]);
}
$tmp = (string)($file['tmp_name'] ?? ''); $size = (int)($file['size'] ?? 0);
if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1) respond(400, ['success'=>false,'message'=>'上传文件为空或无效。']);
if ($size > $maxBytes) respond(413, ['success'=>false,'message'=>'图片超过大小限制（'.round($maxBytes/1024/1024,1).' MiB）。']);
if (!class_exists('finfo')) respond(500, ['success'=>false,'message'=>'服务器未启用 PHP fileinfo 扩展。']);
$finfo = new finfo(FILEINFO_MIME_TYPE); $mime = (string)$finfo->file($tmp); $imageInfo = @getimagesize($tmp);
$types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
if (!isset($types[$mime]) || !is_array($imageInfo) || !isset($imageInfo['mime']) || !isset($types[(string)$imageInfo['mime']]) || $mime !== (string)$imageInfo['mime']) respond(415, ['success'=>false,'message'=>'仅支持有效的 PNG、JPG、WebP 或 GIF 图片。']);
if ((int)$imageInfo[0] < 1 || (int)$imageInfo[1] < 1 || (int)$imageInfo[0]*(int)$imageInfo[1] > 24000000) respond(413, ['success'=>false,'message'=>'图片尺寸无效或像素总量超过 2400 万。']);
$content = @file_get_contents($tmp);
if ($content === false || $content === '') respond(400, ['success'=>false,'message'=>'读取上传图片失败。']);
$now = new DateTimeImmutable('now'); $ext = $types[$mime];
$rawPrefix = pathinfo((string)($file['name'] ?? 'image'), PATHINFO_FILENAME);
$safePrefix = preg_replace('/[^A-Za-z0-9_\\-\\x{4e00}-\\x{9fa5}]+/u', '_', (string)$rawPrefix);
$safePrefix = trim(substr((string)$safePrefix, 0, 60), '_-'); if ($safePrefix === '') $safePrefix = 'image';
$repoPath = 'uploads/'.$now->format('Y/m').'/'.$now->format('Ymd-His').'-'.bin2hex(random_bytes(8)).'-'.$safePrefix.'.'.$ext;
$segments = array_map('rawurlencode', explode('/', $repoPath));
$repoParts = array_map('rawurlencode', explode('/', $repo));
$apiUrl = 'https://api.github.com/repos/'.implode('/', $repoParts).'/contents/'.implode('/', $segments);
$payload = ['message'=>'Upload image via secure image QR tool','content'=>base64_encode($content),'branch'=>$branch];
try {
    $result = github_api('PUT', $apiUrl, $token, $payload);
    if ($result['status'] !== 201 && $result['status'] !== 200) {
        $message = api_error_message((int)$result['status'], $result['body']);
        log_upload(['uploadedAt'=>date(DATE_ATOM),'path'=>$repoPath,'status'=>'failed','httpStatus'=>$result['status'],'message'=>$message]);
        $status = $result['status'] >= 400 && $result['status'] <= 599 ? $result['status'] : 502;
        respond($status, ['success'=>false,'message'=>$message]);
    }
    $imageUrl = 'https://raw.githubusercontent.com/'.$repo.'/'.rawurlencode($branch).'/'.implode('/', $segments);
    $filename = basename($repoPath);
    log_upload(['uploadedAt'=>date(DATE_ATOM),'filename'=>$filename,'path'=>$repoPath,'imageUrl'=>$imageUrl,'status'=>'success','sizeBytes'=>$size]);
    respond(200, ['success'=>true,'filename'=>$filename,'path'=>$repoPath,'imageUrl'=>$imageUrl,'message'=>'图片已成功上传到 GitHub 公开仓库。']);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : '上传处理失败，请查看服务器 PHP 错误日志。';
    log_upload(['uploadedAt'=>date(DATE_ATOM),'path'=>$repoPath,'status'=>'failed','message'=>$message]);
    respond(502, ['success'=>false,'message'=>$message]);
}
