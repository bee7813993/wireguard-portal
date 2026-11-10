<?php
// =========================================================
//  manage.php  – 利用者向け設定変更画面
//  ポート番号と管理トークンで、そのポートの設定を変更する。
//  GET: 画面表示 / POST (JSON): 操作 API
// =========================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wg_manager.php';

$delete_mode = get_setting('delete_mode') ?: 'none';

// ---- 操作 API --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF 簡易チェック (同一オリジン確認)
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    if ($host && $origin && !str_contains($origin, $host)) {
        http_response_code(403);
        echo json_encode(['error' => '不正なリクエストです。']);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($body['action'] ?? '');

    try {
        $port = validate_port($body['port'] ?? null);

        // ポートの有無とトークンの誤りを区別しない
        if (!WgManager::verify_token($port, (string)($body['token'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['error' => 'ポート番号か管理トークンが正しくありません。']);
            exit;
        }

        switch ($action) {
            case 'info':
                echo json_encode(['ok' => true, 'data' => WgManager::port_info($port)]);
                break;

            case 'conf':
                echo json_encode(['ok' => true, 'data' => WgManager::rebuild_client_conf($port)]);
                break;

            case 'update':
                $info  = WgManager::port_info($port);
                $mode  = validate_proxy_mode($body['proxy_mode'] ?? $info['proxy_mode']);
                $dest  = validate_dest_port($body['dest_port'] ?? $info['dest_port']);
                $owner = array_key_exists('owner_name', $body) ? validate_owner_name($body['owner_name']) : $info['owner_name'];
                // 入力を全部確かめてから保存する (名前はサーバーへの反映が要らない)
                $name_changed = WgManager::set_owner_name($port, $owner);
                $res  = WgManager::update_port($port, $mode, $dest);
                echo json_encode(['ok' => true, 'data' => $res + [
                    'name_changed' => $name_changed,
                    'auto_apply'   => get_setting('auto_apply') === '1',
                ]]);
                break;

            case 'delete':
                if ($delete_mode === 'admin') {
                    http_response_code(403);
                    echo json_encode(['error' => '削除は管理者のみ行えます。']);
                    break;
                }
                $res = WgManager::remove($port);
                write_log('INFO', "ユーザーが設定変更画面からポート {$port} を削除しました");
                echo json_encode(['ok' => true, 'data' => $res]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => '不正な操作です。']);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'サーバーエラー: ' . $e->getMessage()]);
    }
    exit;
}

// ---- 画面表示 --------------------------------------------
$https_ready = WgManager::https_ready();
$https_note  = get_setting('https_note');
$notice      = get_setting('notice');
$auto_apply  = get_setting('auto_apply') === '1';
$prefill     = (int)($_GET['port'] ?? 0);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>設定変更 – WireGuard Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+JP:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="portal.css">
</head>
<body>

<header>
  <div class="logo-mark">
    <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="8" cy="4" r="2" stroke="#059669" stroke-width="1.5"/>
      <circle cx="3" cy="12" r="2" stroke="#059669" stroke-width="1.5"/>
      <circle cx="13" cy="12" r="2" stroke="#059669" stroke-width="1.5"/>
      <line x1="8" y1="6" x2="3" y2="10" stroke="#059669" stroke-width="1"/>
      <line x1="8" y1="6" x2="13" y2="10" stroke="#059669" stroke-width="1"/>
    </svg>
  </div>
  <span class="logo-text">WireGuard <span>Portal</span></span>
</header>

<main>
  <div class="hero">
    <h1>発行済みポートの<em>設定変更</em></h1>
    <p>ポート番号と、発行時に表示された管理トークンを入力してください。</p>
  </div>

  <?php if ($notice !== ''): ?>
  <!-- 管理者からのお知らせ -->
  <div class="notice-box"><?= message_html($notice) ?></div>
  <?php endif; ?>

  <!-- 認証 -->
  <div class="card" id="auth-card">
    <div class="input-group">
      <div class="input-wrap">
        <label>ポート番号</label>
        <input type="number" id="port-input" min="1024" max="65535" placeholder="11090"
               value="<?= $prefill ?: '' ?>" autocomplete="off">
      </div>
    </div>
    <div class="input-group">
      <div class="input-wrap">
        <label>管理トークン</label>
        <input type="text" id="token-input" placeholder="設定生成時に表示されたトークン" autocomplete="off">
        <div class="error-msg" id="auth-error"></div>
        <div class="field-hint">管理トークンを忘れた方は、管理者にご連絡ください（再発行すると古いトークンは使えなくなります）。</div>
      </div>
    </div>
    <button class="btn-generate" id="auth-btn" onclick="doOpen()">
      <span id="auth-label">開く</span>
    </button>
  </div>

  <!-- 設定 -->
  <div class="card" id="manage-card" style="display:none">
    <dl class="info-list">
      <dt>ポート</dt>       <dd id="i-port"></dd>
      <dt>お名前</dt>       <dd id="i-owner"></dd>
      <dt>公開 URL</dt>     <dd id="i-url"></dd>
      <dt>公開方式</dt>     <dd id="i-mode"></dd>
      <dt>転送先</dt>       <dd id="i-dest"></dd>
      <dt>最終更新</dt>     <dd id="i-updated"></dd>
    </dl>

    <div class="section-head" style="margin-top:0">設定の変更</div>
    <div class="input-group">
      <div class="input-wrap">
        <label>お名前</label>
        <input type="text" id="owner-input" maxlength="<?= OWNER_NAME_MAX ?>" autocomplete="off" placeholder="例: 山田">
        <div class="field-hint">管理者が、誰のポートかを見分けるために使います (ほかの利用者には表示されません)。</div>
      </div>
    </div>
    <label class="field-label">公開方式</label>
    <div class="mode-group">
      <label class="mode-opt"><input type="radio" name="proxy_mode" value="dnat"> HTTP</label>
      <label class="mode-opt<?= $https_ready ? '' : ' disabled' ?>">
        <input type="radio" name="proxy_mode" value="https" <?= $https_ready ? '' : 'disabled' ?>> HTTPS
      </label>
    </div>
    <div class="mode-note">
      <?php if ($https_ready): ?>
        <?= message_html($https_note) ?><br>
      <?php else: ?>
        HTTPS 公開は管理者が設定していないため選べません。<br>
      <?php endif; ?>
      鍵は変わらないので、WireGuard の設定ファイルを入れ直す必要はありません。
      <?php if (!$auto_apply): ?><br>※ 自動適用が無効のため、管理者がサーバーへ反映するまで変更は有効になりません。<?php endif; ?>
    </div>
    <div class="input-group">
      <div class="input-wrap">
        <label>転送先ポート (PC 側)</label>
        <input type="number" id="dest-port-input" min="1" max="65535" autocomplete="off">
      </div>
    </div>
    <button class="btn-generate" id="save-btn" onclick="doUpdate()">
      <span id="save-label">変更を保存する</span>
    </button>
    <div id="save-result"></div>

    <div class="section-head">クライアント設定 (WireGuard)</div>
    <p class="mode-note" style="margin-top:0">発行時と同じ設定ファイルをもう一度取得できます。</p>
    <button class="dl-btn" style="margin-top:0" onclick="doConf()">設定を表示する</button>
    <div id="conf-area" style="display:none;margin-top:12px">
      <div class="code-block" id="conf-block">
        <button class="copy-btn" onclick="copyBlock('conf-block')">コピー</button>
      </div>
      <button class="dl-btn" id="dl-btn" onclick="downloadConf()">↓ .conf をダウンロード</button>
    </div>

    <?php if ($delete_mode !== 'admin'): ?>
    <div class="section-head">設定の削除</div>
    <p class="mode-note" style="margin-top:0">このポートの設定を削除します。<strong>この操作は元に戻せません。</strong></p>
    <button class="btn-delete" id="del-btn" onclick="doDelete()">
      <span id="del-label">設定を削除する</span>
    </button>
    <div id="del-result"></div>
    <?php endif; ?>
  </div>
</main>

<footer>
  <a href="index.php">トップ</a> &nbsp;·&nbsp; <a href="admin.php">管理画面</a>
</footer>

<script>
let cur = { port: 0, token: '' };
let confText = '';

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function api(action, extra = {}) {
  const res = await fetch('manage.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, port: cur.port, token: cur.token, ...extra })
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok || !json.ok) throw new Error(json.error || 'エラーが発生しました。');
  return json.data;
}

function showInfo(d) {
  document.getElementById('i-port').textContent = d.port;
  document.getElementById('i-owner').textContent = d.owner_name || '(未登録)';
  document.getElementById('owner-input').value = d.owner_name;
  const url = document.getElementById('i-url');
  url.innerHTML = '';
  const a = document.createElement('a');
  a.href = d.public_url; a.textContent = d.public_url; a.className = 'sub-link';
  a.target = '_blank'; a.rel = 'noopener';
  url.appendChild(a);
  document.getElementById('i-mode').innerHTML = d.proxy_mode === 'https'
    ? '<span class="mode-badge https">HTTPS</span>' : '<span class="mode-badge">HTTP</span>';
  document.getElementById('i-dest').textContent = `PC (${d.client_ip}) のポート ${d.dest_port}`;
  document.getElementById('i-updated').textContent = d.updated_at;

  const radio = document.querySelector(`input[name="proxy_mode"][value="${d.proxy_mode}"]`);
  if (radio) radio.checked = true;
  document.getElementById('dest-port-input').value = d.dest_port;
}

function applyHtml(applied) {
  if (!applied) return '';
  const ok = applied.success;
  return `<div class="apply-card ${ok ? 'apply-ok' : 'apply-err'}" style="margin-top:1rem">` +
    `<div class="apply-title">${ok ? '✓ サーバーへ反映しました' : '✗ サーバーへの反映に失敗したため、変更を元に戻しました'}</div>` +
    (applied.output ? `<div class="apply-output">${escHtml(applied.output)}</div>` : '') +
    `</div>`;
}

async function doOpen() {
  const errEl = document.getElementById('auth-error');
  const btn   = document.getElementById('auth-btn');
  const label = document.getElementById('auth-label');
  errEl.textContent = '';
  cur.port  = parseInt(document.getElementById('port-input').value, 10);
  cur.token = document.getElementById('token-input').value.trim();
  if (!cur.port || cur.port < 1024 || cur.port > 65535) { errEl.textContent = 'ポート番号は 1024〜65535 の範囲で入力してください。'; return; }
  if (!cur.token) { errEl.textContent = '管理トークンを入力してください。'; return; }

  btn.disabled = true;
  label.innerHTML = '<span class="spinner"></span>';
  try {
    showInfo(await api('info'));
    document.getElementById('auth-card').style.display = 'none';
    document.getElementById('manage-card').style.display = 'block';
  } catch (e) {
    errEl.textContent = e.message;
  } finally {
    btn.disabled = false;
    label.textContent = '開く';
  }
}

async function doUpdate() {
  const resultEl = document.getElementById('save-result');
  const btn   = document.getElementById('save-btn');
  const label = document.getElementById('save-label');
  const mode  = (document.querySelector('input[name="proxy_mode"]:checked') || {}).value;
  const dest  = parseInt(document.getElementById('dest-port-input').value, 10);
  const owner = document.getElementById('owner-input').value.trim();
  resultEl.innerHTML = '';
  if (!owner) {
    resultEl.innerHTML = '<div class="del-err">お名前を入力してください。</div>';
    return;
  }
  if (!dest || dest < 1 || dest > 65535) {
    resultEl.innerHTML = '<div class="del-err">転送先ポートは 1〜65535 の範囲で入力してください。</div>';
    return;
  }

  btn.disabled = true;
  label.innerHTML = '<span class="spinner"></span>';
  try {
    const d = await api('update', { proxy_mode: mode, dest_port: dest, owner_name: owner });
    showInfo(d.info);
    if (!d.changed) {
      resultEl.innerHTML = d.name_changed
        ? '<div class="del-ok">保存しました。</div>'
        : '<div class="del-ok">変更はありません。</div>';
    } else if (d.applied) {
      resultEl.innerHTML = applyHtml(d.applied);
    } else {
      resultEl.innerHTML = '<div class="del-ok">保存しました。管理者がサーバーへ反映すると有効になります。</div>';
    }
  } catch (e) {
    resultEl.innerHTML = `<div class="del-err">${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled = false;
    label.textContent = '変更を保存する';
  }
}

async function doConf() {
  try {
    const d = await api('conf');
    confText = d.client_conf;
    const block = document.getElementById('conf-block');
    const copy  = block.querySelector('.copy-btn');
    block.textContent = confText;
    block.appendChild(copy);
    document.getElementById('dl-btn').textContent = `↓ wg-client_${d.port}.conf をダウンロード`;
    document.getElementById('conf-area').style.display = 'block';
  } catch (e) {
    alert(e.message);
  }
}

function downloadConf() {
  if (!confText) return;
  const blob = new Blob([confText], { type: 'text/plain' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `wg-client_${cur.port}.conf`;
  a.click();
}

function copyBlock(id) {
  const btn = document.getElementById(id).querySelector('.copy-btn');
  navigator.clipboard.writeText(confText).then(() => {
    btn.textContent = '完了';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'コピー'; btn.classList.remove('copied'); }, 1800);
  });
}

async function doDelete() {
  if (!confirm(`ポート ${cur.port} の設定を削除しますか？\nこの操作は元に戻せません。`)) return;
  const resultEl = document.getElementById('del-result');
  const btn   = document.getElementById('del-btn');
  const label = document.getElementById('del-label');
  btn.disabled = true;
  label.innerHTML = '<span class="spinner"></span>';
  try {
    await api('delete');
    resultEl.innerHTML = `<div class="del-ok">ポート ${cur.port} の設定を削除しました。</div>`;
    document.getElementById('save-btn').disabled = true;
  } catch (e) {
    resultEl.innerHTML = `<div class="del-err">${escHtml(e.message)}</div>`;
    btn.disabled = false;
  } finally {
    label.textContent = '設定を削除する';
  }
}

['port-input', 'token-input'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') doOpen(); });
});
</script>
</body>
</html>
