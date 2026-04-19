<?php
// =========================================================
//  config.php  – DB接続 & 共通ユーティリティ
// =========================================================

define('DB_PATH', __DIR__ . '/data/wg_portal.sqlite');
define('ADMIN_SESSION_KEY', 'wg_admin_authed');

// ---- DB接続 (SQLite) ------------------------------------
function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL");

    // テーブル初期化
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wg_configs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            port        INTEGER NOT NULL UNIQUE,
            client_priv TEXT    NOT NULL,
            client_pub  TEXT    NOT NULL,
            server_priv TEXT    NOT NULL,
            server_pub  TEXT    NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ");

    // デフォルト設定を挿入 (初回のみ)
    $defaults = [
        'vps_ip'       => '203.0.113.1',
        'wg_port'      => '51820',
        'nic'          => 'eth0',
        'subnet'       => '10.0.0',
        'wg_interface' => 'wg0',
        'auto_apply'   => '0',
        'admin_pass'   => password_hash('changeme', PASSWORD_DEFAULT),
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)");
    foreach ($defaults as $k => $v) $stmt->execute([':k' => $k, ':v' => $v]);

    return $pdo;
}

// ---- 設定値取得 ------------------------------------------
function get_setting(string $key): string {
    $row = get_db()->prepare("SELECT value FROM settings WHERE key = ?");
    $row->execute([$key]);
    return (string)($row->fetchColumn() ?? '');
}

// ---- 設定値保存 ------------------------------------------
function set_setting(string $key, string $value): void {
    get_db()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")
            ->execute([$key, $value]);
}

// ---- 管理者認証チェック ----------------------------------
function require_admin(): void {
    session_start();
    if (empty($_SESSION[ADMIN_SESSION_KEY])) {
        header('Location: admin.php');
        exit;
    }
}

// ---- ログ記録 ------------------------------------------
function write_log(string $level, string $message): void {
    $log_path = dirname(DB_PATH) . '/portal.log';
    $line = date('Y-m-d H:i:s') . " [{$level}] " . $message . PHP_EOL;
    file_put_contents($log_path, $line, FILE_APPEND | LOCK_EX);
}

// ---- グローバルサーバー鍵 (インターフェース用・初回生成) ----
function get_or_create_server_keypair(): array {
    $priv = get_setting('server_priv');
    $pub  = get_setting('server_pub');
    if ($priv && $pub) return ['priv' => $priv, 'pub' => $pub];

    $kp = wg_generate_keypair();
    set_setting('server_priv', $kp['priv']);
    set_setting('server_pub',  $kp['pub']);
    return $kp;
}

// ---- WireGuard 鍵生成 (Curve25519) ----------------------
// PHP拡張なしで動作する純粋PHP実装
function wg_generate_keypair(): array {
    // sodium 拡張が使えれば最速
    if (function_exists('sodium_crypto_box_keypair')) {
        $kp   = sodium_crypto_box_keypair();
        $priv = sodium_crypto_box_secretkey($kp);
        $pub  = sodium_crypto_box_publickey($kp);
        // Clamp private key per RFC 7748
        $priv[0]  = chr(ord($priv[0])  & 0xF8);
        $priv[31] = chr((ord($priv[31]) & 0x7F) | 0x40);
        return [
            'priv' => base64_encode($priv),
            'pub'  => base64_encode($pub),
        ];
    }

    // フォールバック: system wg コマンド
    if (is_executable('/usr/bin/wg') || is_executable('/usr/local/bin/wg')) {
        $priv = trim(shell_exec('wg genkey'));
        $pub  = trim(shell_exec("echo " . escapeshellarg($priv) . " | wg pubkey"));
        if ($priv && $pub) return ['priv' => $priv, 'pub' => $pub];
    }

    throw new RuntimeException('鍵生成に失敗しました。sodium PHP拡張 または wg コマンドが必要です。');
}

// ---- ポート番号バリデーション ----------------------------
function validate_port(mixed $port): int {
    $p = (int)$port;
    if ($p < 1024 || $p > 65535) {
        throw new InvalidArgumentException('ポート番号は 1024〜65535 の範囲で指定してください。');
    }
    return $p;
}
