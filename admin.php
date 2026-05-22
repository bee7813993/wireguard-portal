<!DOCTYPE html>
<?php
// =========================================================
//  admin.php  – 管理画面: 内部パラメーター設定
// =========================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wg_manager.php';
session_start();

$error   = '';
$success = '';

// ---- ログアウト -----------------------------------------
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---- ログイン処理 ----------------------------------------
if (!isset($_SESSION[ADMIN_SESSION_KEY])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $stored = get_setting('admin_pass');
        if (password_verify($_POST['password'], $stored)) {
            $_SESSION[ADMIN_SESSION_KEY] = true;
            header('Location: admin.php');
            exit;
        }
        $error = 'パスワードが正しくありません。';
    }
    ?>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理画面ログイン – WireGuard Portal</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+JP:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#f0f4f8;--surface:#fff;--border:#dde5ef;--border2:#c9d5e4;--accent:#059669;--text:#1e293b;--muted:#6b7f96;--mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans JP',sans-serif;}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh;display:grid;place-items:center}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:2.5rem 2rem;width:100%;max-width:360px;box-shadow:0 4px 20px rgba(30,50,80,.1)}
h1{font-family:var(--mono);font-size:18px;font-weight:500;margin-bottom:1.75rem;color:var(--text)}
h1 span{color:var(--accent)}
label{display:block;font-size:11px;font-family:var(--mono);color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-bottom:8px}
input[type=password]{width:100%;background:var(--bg);border:1.5px solid var(--border2);border-radius:9px;padding:11px 14px;font-family:var(--mono);font-size:15px;color:var(--text);outline:none;transition:border-color .15s,box-shadow .15s;margin-bottom:1rem}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(5,150,105,.1)}
button{width:100%;padding:12px;background:var(--accent);color:#fff;font-family:var(--mono);font-size:14px;font-weight:500;border:none;border-radius:9px;cursor:pointer;transition:opacity .15s;box-shadow:0 2px 8px rgba(5,150,105,.3)}
button:hover{opacity:.9}
.err{font-size:13px;color:#dc2626;font-family:var(--mono);margin-bottom:1rem;background:#fef2f2;border:1px solid #fca5a5;padding:8px 12px;border-radius:7px}
.back{display:block;text-align:center;margin-top:1.25rem;font-size:12px;font-family:var(--mono);color:var(--muted);text-decoration:none}
.back:hover{color:var(--accent)}
</style>
</head>
<body>
<div class="card">
  <h1>Admin <span>Login</span></h1>
  <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <form method="post">
    <label>パスワード</label>
    <input type="password" name="password" autofocus autocomplete="current-password">
    <button type="submit">ログイン</button>
  </form>
  <a href="index.php" class="back">← トップへ戻る</a>
</div>
</body>
</html>
    <?php exit; }

// ---- WireGuard停止 & iptablesクリア ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'teardown') {
    $res = WgManager::teardown();
    if ($res['success']) {
        $success = 'WireGuardを停止し、iptablesルールをクリアしました。';
    } else {
        $error = 'WireGuard停止に失敗しました: ' . $res['output'];
    }
    write_log('INFO', '管理者がWireGuardを停止しiptablesをクリアしました');
}

// ---- ポート削除 ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_port') {
    $del_port = (int)($_POST['del_port'] ?? 0);
    if ($del_port >= 1024 && $del_port <= 65535) {
        $deleted = get_db()->prepare("DELETE FROM wg_configs WHERE port = ?")->execute([$del_port]);
        write_log('INFO', "管理者がポート {$del_port} を削除しました");
        if (get_setting('auto_apply') === '1') {
            $res = WgManager::apply_server_config();
            if ($res['success']) {
                $success = "ポート {$del_port} を削除し、サーバーへ反映しました。";
            } else {
                $error = "ポート {$del_port} を削除しましたが、サーバーへの適用に失敗しました: " . $res['output'];
            }
        } else {
            $success = "ポート {$del_port} を削除しました。";
        }
    }
}

// ---- 設定保存 --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $fields = ['vps_ip','wg_port','nic','subnet','wg_interface'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) set_setting($f, trim($_POST[$f]));
    }
    set_setting('auto_apply', isset($_POST['auto_apply']) ? '1' : '0');
    if (!empty($_POST['new_pass'])) {
        if ($_POST['new_pass'] === ($_POST['new_pass_confirm'] ?? '')) {
            set_setting('admin_pass', password_hash($_POST['new_pass'], PASSWORD_DEFAULT));
            $success = 'パスワードを変更しました。';
        } else {
            $error = '新しいパスワードが一致しません。';
        }
    }
    if (!$error) $success = $success ?: '設定を保存しました。';
    write_log('INFO', '管理者が設定を変更しました');
}

// ---- 現在値 -----------------------------------------------
$cfg = [
    'vps_ip'       => get_setting('vps_ip'),
    'wg_port'      => get_setting('wg_port'),
    'nic'          => get_setting('nic'),
    'subnet'       => get_setting('subnet'),
    'wg_interface' => get_setting('wg_interface') ?: 'wg0',
    'auto_apply'   => get_setting('auto_apply'),
];

// ---- 発行済みポート一覧 -----------------------------------
$rows = get_db()->query("SELECT port, client_pub, created_at, updated_at FROM wg_configs ORDER BY updated_at DESC LIMIT 100")->fetchAll();

// ---- ログ読み込み ----------------------------------------
$log_path  = dirname(DB_PATH) . '/portal.log';
$log_lines = [];
if (file_exists($log_path)) {
    $all       = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $log_lines = array_reverse(array_slice($all, -200));
}
?>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理画面 – WireGuard Portal</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+JP:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f0f4f8;--surface:#fff;--border:#dde5ef;--border2:#c9d5e4;
  --accent:#059669;--accent2:#0284c7;--text:#1e293b;--muted:#6b7f96;
  --err:#dc2626;--mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans JP',sans-serif;
  --shadow:0 2px 12px rgba(30,50,80,.08);--shadow2:0 1px 4px rgba(30,50,80,.06);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:1rem 2rem;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow2)}
.logo{font-family:var(--mono);font-size:14px;letter-spacing:.06em;color:var(--text)}
.logo span{color:var(--accent)}
.header-links{display:flex;align-items:center;gap:.75rem}
.top-link{font-family:var(--mono);font-size:12px;color:var(--accent2);text-decoration:none;padding:4px 12px;border:1px solid #bae6fd;border-radius:6px;background:#f0f9ff;transition:background .12s,border-color .12s}
.top-link:hover{background:#e0f2fe;border-color:var(--accent2)}
.logout{font-family:var(--mono);font-size:12px;color:var(--muted);text-decoration:none;padding:4px 12px;border:1px solid var(--border2);border-radius:6px;background:var(--surface);transition:background .12s,color .12s}
.logout:hover{color:var(--text);background:#f3f7fa}
main{max-width:860px;margin:0 auto;padding:2.5rem 1.5rem;display:flex;flex-direction:column;gap:2rem}
.section-title{font-family:var(--mono);font-size:11px;font-weight:500;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:1rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.75rem;box-shadow:var(--shadow)}
.field{margin-bottom:1rem}
.field:last-of-type{margin-bottom:0}
label{display:block;font-size:11px;font-family:var(--mono);color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-bottom:7px}
.desc{font-size:12px;color:var(--muted);margin-bottom:7px}
input[type=text],input[type=number],input[type=password]{width:100%;background:var(--bg);border:1.5px solid var(--border2);border-radius:9px;padding:9px 13px;font-family:var(--mono);font-size:14px;color:var(--text);outline:none;transition:border-color .15s,box-shadow .15s}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(5,150,105,.1)}
.divider{border:none;border-top:1px solid var(--border);margin:1.5rem 0}
.save-btn{padding:10px 28px;background:var(--accent);color:#fff;font-family:var(--mono);font-size:13px;font-weight:500;border:none;border-radius:9px;cursor:pointer;transition:opacity .15s;box-shadow:0 2px 8px rgba(5,150,105,.25)}
.save-btn:hover{opacity:.9}
.msg-ok{font-size:13px;color:#166534;font-family:var(--mono);margin-bottom:1rem;background:#f0fdf4;border:1px solid #86efac;padding:10px 14px;border-radius:8px}
.msg-err{font-size:13px;color:var(--err);font-family:var(--mono);margin-bottom:1rem;background:#fef2f2;border:1px solid #fca5a5;padding:10px 14px;border-radius:8px}
table{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:12px}
th{text-align:left;padding:10px 14px;color:var(--muted);font-weight:500;background:#f8fafc;border-bottom:1px solid var(--border);letter-spacing:.04em;font-size:11px}
td{padding:10px 14px;border-bottom:1px solid var(--border);color:var(--text);word-break:break-all;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafbfc}
.port-badge{display:inline-block;padding:3px 11px;background:#d1fae5;color:#065f46;border-radius:5px;font-size:11px;font-weight:500}
.no-rows{text-align:center;padding:2rem;color:var(--muted);font-family:var(--mono);font-size:13px}
.del-btn{padding:4px 13px;background:transparent;border:1px solid #fca5a5;border-radius:5px;color:var(--err);font-family:var(--mono);font-size:11px;cursor:pointer;transition:background .12s,border-color .12s;white-space:nowrap}
.del-btn:hover{background:#fef2f2;border-color:var(--err)}
.stop-btn{padding:10px 24px;background:transparent;border:1.5px solid #fca5a5;border-radius:9px;color:var(--err);font-family:var(--mono);font-size:13px;font-weight:500;cursor:pointer;transition:background .12s,border-color .12s}
.stop-btn:hover{background:#fef2f2;border-color:var(--err)}
.log-area{max-height:360px;overflow-y:auto;padding:1rem;background:#f8fafc;font-family:var(--mono);font-size:11px;line-height:1.75;border-radius:0 0 14px 14px}
.log-line{color:var(--muted);white-space:pre-wrap;word-break:break-all}
.log-line.info{color:var(--text)}
.log-line.error{color:var(--err)}
.no-log{text-align:center;padding:2rem;color:var(--muted);font-family:var(--mono);font-size:13px}
</style>
</head>
<body>
<header>
  <span class="logo">WireGuard <span>Portal</span> — Admin</span>
  <div class="header-links">
    <a href="index.php" class="top-link">← トップ</a>
    <a href="?logout=1" class="logout">ログアウト</a>
  </div>
</header>

<main>

  <!-- システム設定 -->
  <div>
    <div class="section-title">システム設定</div>
    <div class="card">
      <?php if ($success): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>
      <?php if ($error):   ?><p class="msg-err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="post">
        <div class="field">
          <label>VPS グローバル IP</label>
          <div class="desc">クライアントが接続する WireGuard サーバーの IP</div>
          <input type="text" name="vps_ip" value="<?= htmlspecialchars($cfg['vps_ip']) ?>" placeholder="203.0.113.1">
        </div>
        <div class="field">
          <label>WireGuard ポート (UDP)</label>
          <div class="desc">VPS が待ち受ける WireGuard のポート</div>
          <input type="number" name="wg_port" value="<?= htmlspecialchars($cfg['wg_port']) ?>" min="1024" max="65535">
        </div>
        <div class="field">
          <label>外向き NIC 名</label>
          <div class="desc">iptables PREROUTING で使う VPS のネットワークインターフェース名 (ip a で確認)</div>
          <input type="text" name="nic" value="<?= htmlspecialchars($cfg['nic']) ?>" placeholder="eth0">
        </div>
        <div class="field">
          <label>WireGuard サブネット (先頭3オクテット)</label>
          <div class="desc">例: 10.0.0 → サーバー 10.0.0.1, クライアントは 10.0.0.2〜</div>
          <input type="text" name="subnet" value="<?= htmlspecialchars($cfg['subnet']) ?>" placeholder="10.0.0">
        </div>
        <div class="field">
          <label>WireGuard インターフェース名</label>
          <div class="desc">wg0 がすでに使用中の場合は wg1 や vpn0 など別名を指定</div>
          <input type="text" name="wg_interface" value="<?= htmlspecialchars($cfg['wg_interface']) ?>" placeholder="wg0">
        </div>
        <div class="field">
          <label>設定生成時に自動適用</label>
          <div class="desc">有効にすると生成ボタン押下時に /etc/wireguard/{interface}.conf へ書き込み wg-quick で適用します (要 root 権限)</div>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text);text-transform:none;letter-spacing:0;margin-top:6px;cursor:pointer">
            <input type="checkbox" name="auto_apply" value="1" <?= $cfg['auto_apply'] === '1' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)">
            自動適用を有効にする
          </label>
        </div>

        <div class="divider"></div>
        <div class="section-title" style="margin-bottom:1rem">パスワード変更 (省略可)</div>
        <div class="field">
          <label>新しいパスワード</label>
          <input type="password" name="new_pass" autocomplete="new-password">
        </div>
        <div class="field">
          <label>新しいパスワード (確認)</label>
          <input type="password" name="new_pass_confirm" autocomplete="new-password">
        </div>

        <div class="divider"></div>
        <button type="submit" class="save-btn">保存する</button>
      </form>
    </div>
  </div>

  <!-- WireGuard制御 -->
  <div>
    <div class="section-title">WireGuard 制御</div>
    <div class="card">
      <p style="font-size:13px;color:var(--muted);margin-bottom:1.25rem;line-height:1.7">
        WireGuardインターフェースを停止し、設定された全ての iptables ルール（DNAT / FORWARD / MASQUERADE）を削除します。<br>
        使用をやめる場合や、iptables のルールが残留している場合はこのボタンを押してください。
      </p>
      <form method="post" onsubmit="return confirm('WireGuardを停止し、iptablesルールをすべて削除します。よろしいですか？')">
        <input type="hidden" name="action" value="teardown">
        <button type="submit" class="stop-btn">WireGuard 停止 &amp; iptables クリア</button>
      </form>
    </div>
  </div>

  <!-- 発行済みポート一覧 -->
  <div>
    <div class="section-title">発行済みポート一覧</div>
    <div class="card" style="padding:0;overflow:hidden">
      <?php if (empty($rows)): ?>
        <p class="no-rows">まだ発行されていません</p>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ポート</th>
            <th>クライアント公開鍵</th>
            <th>最終更新</th>
            <th style="width:72px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><span class="port-badge"><?= (int)$r['port'] ?></span></td>
            <td style="color:var(--muted)"><?= htmlspecialchars(substr($r['client_pub'],0,24)) ?>…</td>
            <td style="color:var(--muted)"><?= htmlspecialchars($r['updated_at']) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('ポート <?= (int)$r['port'] ?> を削除しますか？\nこの操作は元に戻せません。')">
                <input type="hidden" name="action" value="delete_port">
                <input type="hidden" name="del_port" value="<?= (int)$r['port'] ?>">
                <button type="submit" class="del-btn">削除</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ログ -->
  <div>
    <div class="section-title">アクティビティログ</div>
    <div class="card" style="padding:0;overflow:hidden">
      <?php if (empty($log_lines)): ?>
        <p class="no-log">ログがありません</p>
      <?php else: ?>
        <div class="log-area">
          <?php foreach ($log_lines as $line): ?>
            <?php
              $cls = 'log-line';
              if (str_contains($line, '[INFO]'))  $cls .= ' info';
              if (str_contains($line, '[ERROR]')) $cls .= ' error';
            ?>
            <div class="<?= $cls ?>"><?= htmlspecialchars($line) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</main>
</body>
</html>
