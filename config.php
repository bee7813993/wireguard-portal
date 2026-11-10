<?php
// =========================================================
//  config.php  – DB接続 & 共通ユーティリティ
// =========================================================

define('DB_PATH', __DIR__ . '/data/wg_portal.sqlite');
// 同じドメインで複数のポータルを動かしても管理ログインを共有しないよう、
// 設置ディレクトリごとに別のキーにする
define('ADMIN_SESSION_KEY', 'wg_admin_authed_' . substr(hash('sha256', __DIR__), 0, 12));

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
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            port         INTEGER NOT NULL UNIQUE,
            dest_port    INTEGER NOT NULL DEFAULT 80,
            proxy_mode   TEXT    NOT NULL DEFAULT 'dnat',
            client_priv  TEXT    NOT NULL,
            client_pub   TEXT    NOT NULL,
            server_priv  TEXT    NOT NULL,
            server_pub   TEXT    NOT NULL,
            delete_token TEXT    DEFAULT NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ");

    // 既存DBへのカラム追加マイグレーション
    $cols = array_column($pdo->query("PRAGMA table_info(wg_configs)")->fetchAll(), 'name');
    if (!in_array('delete_token', $cols, true)) {
        $pdo->exec("ALTER TABLE wg_configs ADD COLUMN delete_token TEXT DEFAULT NULL");
    }
    // 転送先ポート (DNAT の宛先)。旧レコードは 80 固定だったため既定値も 80
    if (!in_array('dest_port', $cols, true)) {
        $pdo->exec("ALTER TABLE wg_configs ADD COLUMN dest_port INTEGER NOT NULL DEFAULT 80");
    }
    // 公開方式 (dnat = iptables で転送 / https = nginx で TLS を終端して転送)。
    // 旧レコードはすべて DNAT だったため既定値も dnat
    if (!in_array('proxy_mode', $cols, true)) {
        $pdo->exec("ALTER TABLE wg_configs ADD COLUMN proxy_mode TEXT NOT NULL DEFAULT 'dnat'");
    }

    // デフォルト設定を挿入 (初回のみ)
    $defaults = [
        'vps_ip'        => '203.0.113.1',
        'wg_port'       => '51820',
        'nic'           => 'eth0',
        'subnet'        => '10.0.0',
        'subnet_ipv6'   => 'fd00::',
        'wg_interface'  => 'wg0',
        'auto_apply'    => '0',
        'delete_mode'   => 'none',
        'check_listen'  => '1',
        'blocked_ports' => '',
        'allowed_ports' => '',
        'default_dest_port' => '80',
        'default_proxy_mode' => 'dnat',
        'https_host'     => '',
        'https_cert'     => '',
        'https_cert_key' => '',
        'nginx_conf_dir' => '/etc/nginx/wgportal.d',
        'https_note'     => 'HTTPS では、このサーバーで暗号化を終端し、PC へは HTTP で転送します。PC 側のアプリが X-Forwarded-Proto ヘッダーに対応していないと、正しく動かないことがあります。',
        'notice'         => '',
        'admin_pass'    => password_hash('changeme', PASSWORD_DEFAULT),
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

// ---- 管理者が書く表示用の文 (お知らせ・注意書き) ----------
// 保存時: 改行を \n にそろえ、前後の空白を取る
function normalize_message(string $text): string {
    return trim(str_replace(["\r\n", "\r"], "\n", $text));
}

// 表示時: HTML として解釈させず、改行だけを <br> にする
function message_html(string $text): string {
    return nl2br(htmlspecialchars($text, ENT_QUOTES), false);
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

// ---- 転送先ポート番号バリデーション ----------------------
// トンネル内 PC 側の待ち受けポート。VPS の外部ポート (validate_port) と違い
// well-known ポートで待ち受けるのは正常な使い方なので 1 以上を許可する。
function validate_dest_port(mixed $port): int {
    $p = (int)$port;
    if ($p < 1 || $p > 65535) {
        throw new InvalidArgumentException('転送先ポート番号は 1〜65535 の範囲で指定してください。');
    }
    return $p;
}

// ---- 公開方式バリデーション ----------------------------
const PROXY_MODES = ['dnat' => 'HTTP', 'https' => 'HTTPS'];

function validate_proxy_mode(mixed $mode): string {
    $m = (string)$mode;
    if (!array_key_exists($m, PROXY_MODES)) {
        throw new InvalidArgumentException('公開方式の指定が不正です。');
    }
    return $m;
}

// ---- nginx 設定に埋め込む値のバリデーション ---------------
// 生成する nginx 設定へ別のディレクティブを差し込めないよう、
// 使える文字を絞る。空文字は「未設定」として通す。
function validate_hostname(string $host): string {
    if ($host !== '' && !preg_match('/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/', $host)) {
        throw new InvalidArgumentException('HTTPS のホスト名の書式が不正です (例: portal.example.com)。');
    }
    return $host;
}

function validate_abs_path(string $path, string $label): string {
    if ($path !== '' && !preg_match('#^/[A-Za-z0-9._/-]+$#', $path)) {
        throw new InvalidArgumentException("{$label}は / から始まる絶対パスで指定してください (英数字と . _ / - のみ)。");
    }
    return $path;
}

// ---- 管理トークン生成 ------------------------------------
// 設定変更・削除 (token モード) に使う。DB にはハッシュだけを保存する。
function generate_manage_token(): array {
    $plain = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    return [$plain, password_hash($plain, PASSWORD_DEFAULT)];
}

// ---- ポート範囲リストのパース ----------------------------
// "2222, 8000-8100" 形式を [[2222,2222],[8000,8100]] に変換。
// 書式不正の場合は null を返す。空文字は空配列。
function parse_port_ranges(string $spec): ?array {
    $ranges = [];
    if (trim($spec) === '') return $ranges;

    foreach (preg_split('/[,\s]+/', trim($spec)) as $part) {
        if ($part === '') continue;
        if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $part, $m)) {
            $lo = (int)$m[1];
            $hi = (int)$m[2];
        } elseif (preg_match('/^\d{1,5}$/', $part)) {
            $lo = $hi = (int)$part;
        } else {
            return null;
        }
        if ($lo < 1 || $hi > 65535 || $lo > $hi) return null;
        $ranges[] = [$lo, $hi];
    }
    return $ranges;
}

function port_in_ranges(int $port, array $ranges): bool {
    foreach ($ranges as [$lo, $hi]) {
        if ($port >= $lo && $port <= $hi) return true;
    }
    return false;
}

// ---- LISTEN 中の TCP ポート一覧取得 ----------------------
// ss (iproute2) を優先し netstat にフォールバック。
// exec が無効化されている等、取得できない環境では null (チェック不能) を返す。
// ループバック (127.0.0.0/8, ::1) のみで待ち受けるサービスは
// 外部 NIC の DNAT と競合しないため一覧に含めない。
function get_listening_tcp_ports(): ?array {
    if (!function_exists('exec')) return null;

    foreach (['ss -tlnH', 'netstat -tln'] as $cmd) {
        $lines  = [];
        $status = 1;
        @exec($cmd . ' 2>/dev/null', $lines, $status);
        if ($status !== 0 || !$lines) continue;

        $ports = [];
        foreach ($lines as $line) {
            // Local Address:Port 列 (例 0.0.0.0:22, [::]:80, *:443) からポートを抽出。
            // 接続先側は ポートが "*" のため数値末尾にマッチしない。
            foreach (preg_split('/\s+/', trim($line)) as $token) {
                if (!preg_match('/^(.*):(\d{1,5})$/', $token, $m)) continue;
                $addr = trim($m[1], '[]');
                if (str_starts_with($addr, '127.') || $addr === '::1' || str_starts_with($addr, '::1%')
                    || str_starts_with($addr, '::ffff:127.')) {
                    continue;
                }
                $p = (int)$m[2];
                if ($p >= 1 && $p <= 65535) $ports[$p] = true;
            }
        }
        return array_keys($ports);
    }
    return null;
}

// ---- 設定生成時のポート利用可否チェック ------------------
// 範囲チェック (validate_port) 通過後のポートに対し、
// 既存サービスとの衝突・管理者による制限を検査する。
// 削除時には呼ばない (衝突したポートの削除は常に可能にする)。
function assert_port_allowed(int $port): void {
    // 発行済みポートの再発行は制限の対象外
    // (管理画面などからの操作を妨げない)
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM wg_configs WHERE port = ?");
    $stmt->execute([$port]);
    if ((int)$stmt->fetchColumn() > 0) return;

    // 1. 許可リスト (設定時のみ): リスト外は拒否
    $allowed_spec = trim(get_setting('allowed_ports'));
    if ($allowed_spec !== '') {
        $allowed = parse_port_ranges($allowed_spec);
        if ($allowed !== null && $allowed !== [] && !port_in_ranges($port, $allowed)) {
            throw new InvalidArgumentException(
                "ポート {$port} は利用できません。利用可能なポート: {$allowed_spec}"
            );
        }
    }

    // 2. 禁止リスト
    $blocked_spec = trim(get_setting('blocked_ports'));
    if ($blocked_spec !== '') {
        $blocked = parse_port_ranges($blocked_spec);
        if ($blocked !== null && port_in_ranges($port, $blocked)) {
            throw new InvalidArgumentException(
                "ポート {$port} は管理者によって禁止されています。別のポートを指定してください。"
            );
        }
    }

    // 3. 予約ポート: WireGuard 本体と本ポータル自身
    if ($port === (int)get_setting('wg_port')) {
        throw new InvalidArgumentException(
            "ポート {$port} は WireGuard 本体が使用するため利用できません。"
        );
    }
    $portal_port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    if ($portal_port !== 0 && $port === $portal_port) {
        throw new InvalidArgumentException(
            "ポート {$port} はこのポータル自身が使用しているため利用できません。"
        );
    }

    // 4. サーバー上で LISTEN 中の実サービス
    //    (発行済みポートの転送は NAT のため LISTEN には現れず、再発行は妨げない)
    if (get_setting('check_listen') !== '0') {
        $listening = get_listening_tcp_ports();
        if ($listening === null) {
            write_log('WARN', "ポート {$port}: LISTEN 状態を取得できないため使用中チェックをスキップしました");
        } elseif (in_array($port, $listening, true)) {
            throw new InvalidArgumentException(
                "ポート {$port} はサーバー上の別のサービスが使用中のため利用できません。" .
                "このポートに設定すると既存サービスへアクセスできなくなります。"
            );
        }
    }
}
