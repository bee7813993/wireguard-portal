<!DOCTYPE html>
<?php
// =========================================================
//  admin.php  – 管理画面: 内部パラメーター設定
// =========================================================
require_once __DIR__ . '/config.php';
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

    // ログインフォームを表示
    ?>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理画面ログイン – WireGuard Portal</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+JP:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0f12;--surface:#13161b;--border:#1e2330;--border2:#2a3044;--accent:#4ade80;--text:#e2e8f0;--muted:#64748b;--mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans JP',sans-serif;}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh;display:grid;place-items:center}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:2.5rem 2rem;width:100%;max-width:360px}
h1{font-family:var(--mono);font-size:18px;font-weight:500;margin-bottom:1.75rem;color:var(--text)}
h1 span{color:var(--accent)}
label{display:block;font-size:11px;font-family:var(--mono);color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-bottom:8px}
input[type=password]{width:100%;background:var(--bg);border:1px solid var(--border2);border-radius:8px;padding:10px 14px;font-family:var(--mono);font-size:15px;color:var(--text);outline:none;transition:border-color .15s;margin-bottom:1rem}
input:focus{border-color:var(--accent)}
button{width:100%;padding:11px;background:var(--accent);color:#0d1a12;font-family:var(--mono);font-size:14px;font-weight:500;border:none;border-radius:8px;cursor:pointer;transition:opacity .15s}
button:hover{opacity:.85}
.err{font-size:13px;color:#f87171;font-family:var(--mono);margin-bottom:1rem}
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
</div>
</body>
</html>
    <?php exit; }

// ---- 設定保存 --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['vps_ip','wg_port','nic','subnet','wg_interface'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) set_setting($f, trim($_POST[$f]));
    }
    // チェックボックスは未チェック時に送信されないため個別処理
    set_setting('auto_apply', isset($_POST['auto_apply']) ? '1' : '0');
    // パスワード変更
    if (!empty($_POST['new_pass'])) {
        if ($_POST['new_pass'] === ($_POST['new_pass_confirm'] ?? '')) {
            set_setting('admin_pass', password_hash($_POST['new_pass'], PASSWORD_DEFAULT));
            $success = 'パスワードを変更しました。';
        } else {
            $error = '新しいパスワードが一致しません。';
        }
    }
    if (!$error) $success = $success ?: '設定を保存しました。';
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
?>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理画面 – WireGuard Portal</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+JP:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0d0f12;--surface:#13161b;--border:#1e2330;--border2:#2a3044;--accent:#4ade80;--text:#e2e8f0;--muted:#64748b;--err:#f87171;--mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans JP',sans-serif;}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh}
header{border-bottom:1px solid var(--border);padding:1.25rem 2rem;display:flex;align-items:center;justify-content:space-between}
.logo{font-family:var(--mono);font-size:14px;letter-spacing:.08em}
.logo span{color:var(--accent)}
.logout{font-family:var(--mono);font-size:12px;color:var(--muted);text-decoration:none;padding:4px 12px;border:1px solid var(--border2);border-radius:6px}
.logout:hover{color:var(--text);border-color:var(--muted)}
main{max-width:780px;margin:0 auto;padding:2.5rem 1.5rem;display:flex;flex-direction:column;gap:2rem}
h2{font-family:var(--mono);font-size:14px;font-weight:500;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-bottom:1.25rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.75rem}
.field{margin-bottom:1rem}
.field:last-of-type{margin-bottom:0}
label{display:block;font-size:11px;font-family:var(--mono);color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin-bottom:7px}
.desc{font-size:12px;color:var(--muted);margin-bottom:7px;font-family:var(--mono)}
input[type=text],input[type=number],input[type=password]{width:100%;background:var(--bg);border:1px solid var(--border2);border-radius:8px;padding:9px 13px;font-family:var(--mono);font-size:14px;color:var(--text);outline:none;transition:border-color .15s}
input:focus{border-color:var(--accent)}
.divider{border:none;border-top:1px solid var(--border);margin:1.5rem 0}
.save-btn{padding:9px 24px;background:var(--accent);color:#0d1a12;font-family:var(--mono);font-size:13px;font-weight:500;border:none;border-radius:8px;cursor:pointer;transition:opacity .15s}
.save-btn:hover{opacity:.85}
.msg-ok{font-size:13px;color:var(--accent);font-family:var(--mono);margin-bottom:1rem}
.msg-err{font-size:13px;color:var(--err);font-family:var(--mono);margin-bottom:1rem}
table{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:12px}
th{text-align:left;padding:8px 10px;color:var(--muted);font-weight:400;border-bottom:1px solid var(--border);letter-spacing:.04em}
td{padding:9px 10px;border-bottom:1px solid var(--border);color:var(--text);word-break:break-all}
tr:last-child td{border-bottom:none}
.port-badge{display:inline-block;padding:2px 10px;background:rgba(74,222,128,.1);color:var(--accent);border:1px solid rgba(74,222,128,.2);border-radius:4px;font-size:11px}
.no-rows{text-align:center;padding:1.5rem;color:var(--muted);font-family:var(--mono);font-size:13px}
</style>
</head>
<body>
<header>
  <span class="logo">WireGuard <span>Portal</span> — Admin</span>
  <a href="?logout=1" class="logout">ログアウト</a>
</header>

<main>

  <!-- システム設定 -->
  <div>
    <h2>システム設定</h2>
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
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text);text-transform:none;letter-spacing:0;margin-top:4px">
            <input type="checkbox" name="auto_apply" value="1" <?= $cfg['auto_apply'] === '1' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--accent)">
            自動適用を有効にする
          </label>
        </div>

        <div class="divider"></div>
        <h2 style="margin-bottom:1rem">パスワード変更 (省略可)</h2>
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

  <!-- 発行済みポート一覧 -->
  <div>
    <h2>発行済みポート一覧</h2>
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
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><span class="port-badge"><?= (int)$r['port'] ?></span></td>
            <td style="color:var(--muted)"><?= htmlspecialchars(substr($r['client_pub'],0,24)) ?>…</td>
            <td style="color:var(--muted)"><?= htmlspecialchars($r['updated_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</main>
</body>
</html>
