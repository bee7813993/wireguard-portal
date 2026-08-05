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

try {
    $port      = validate_port($port_raw);
    $dest_port = validate_dest_port($dest_raw);
    assert_port_allowed($port);
    $result = WgManager::issue($port, $dest_port);
    echo json_encode(['ok' => true, 'data' => $result]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラー: ' . $e->getMessage()]);
}
