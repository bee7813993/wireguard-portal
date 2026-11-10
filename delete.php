<?php
// =========================================================
//  delete.php  – Ajax API: 設定削除エンドポイント
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

$body     = json_decode(file_get_contents('php://input'), true);
$port_raw = $body['port'] ?? null;

try {
    $port        = validate_port($port_raw);
    $delete_mode = get_setting('delete_mode') ?: 'none';

    // admin モードはセッション認証が必要
    if ($delete_mode === 'admin') {
        session_start();
        if (empty($_SESSION[ADMIN_SESSION_KEY])) {
            http_response_code(403);
            echo json_encode(['error' => '管理者のみ削除できます。管理画面からログインしてください。']);
            exit;
        }
    }

    if (!WgManager::exists($port)) {
        http_response_code(404);
        echo json_encode(['error' => "ポート {$port} の設定が見つかりません。"]);
        exit;
    }

    // token モードは管理トークン照合
    if ($delete_mode === 'token' && !WgManager::verify_token($port, (string)($body['token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => '管理トークンが正しくありません。']);
        exit;
    }

    $applied = WgManager::remove($port)['applied'];
    write_log('INFO', "ユーザーがポート {$port} の設定を削除しました (mode={$delete_mode})");

    echo json_encode(['ok' => true, 'port' => $port, 'applied' => $applied]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラー: ' . $e->getMessage()]);
}
