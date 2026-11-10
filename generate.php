<?php
// =========================================================
//  generate.php  – Ajax API: 設定生成エンドポイント
// =========================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wg_manager.php';

header('Content-Type: application/json; charset=utf-8');

// CSRF 簡易チェック (同一オリジン確認)
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
$host   = $_SERVER['HTTP_HOST'] ?? '';
if ($host && $origin && !str_contains($origin, $host)) {
    http_response_code(403);
    echo json_encode(['error' => '不正なリクエストです。']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$port_raw = $body['port'] ?? $_POST['port'] ?? null;
// 転送先ポート未指定時は管理画面の既定値を使う
$dest_raw = $body['dest_port'] ?? $_POST['dest_port'] ?? null;
if ($dest_raw === null || $dest_raw === '') {
    $dest_raw = get_setting('default_dest_port') ?: '80';
}
// 公開方式未指定時も管理画面の既定値を使う
$mode_raw = $body['proxy_mode'] ?? $_POST['proxy_mode'] ?? null;
if ($mode_raw === null || $mode_raw === '') {
    $mode_raw = get_setting('default_proxy_mode') ?: 'dnat';
}

try {
    $port      = validate_port($port_raw);
    $dest_port = validate_dest_port($dest_raw);
    $mode      = validate_proxy_mode($mode_raw);
    // 発行済みのポートはここでは作り直さない (鍵とトークンを他人に作り直されないように)。
    // 設定の変更・再取得は設定変更画面 (manage.php) で管理トークンを使って行う。
    if (WgManager::exists($port)) {
        http_response_code(409);
        echo json_encode([
            'error'  => "ポート {$port} は発行済みです。設定の変更や設定ファイルの再取得は「設定変更」画面から行ってください。",
            'manage' => true,
        ]);
        exit;
    }
    assert_port_allowed($port);
    $result = WgManager::issue($port, $dest_port, $mode);
    echo json_encode(['ok' => true, 'data' => $result]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラー: ' . $e->getMessage()]);
}
