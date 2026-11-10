<!DOCTYPE html>
<?php
require_once __DIR__ . '/config.php';
$wg_iface    = htmlspecialchars(get_setting('wg_interface') ?: 'wg0');
$auto_apply  = get_setting('auto_apply') === '1';
$delete_mode = get_setting('delete_mode') ?: 'none';
$default_dest_port = (int)(get_setting('default_dest_port') ?: 80) ?: 80;
require_once __DIR__ . '/wg_manager.php';
$https_ready  = WgManager::https_ready();
$default_mode = (get_setting('default_proxy_mode') === 'https' && $https_ready) ? 'https' : 'dnat';
$https_note   = get_setting('https_note');
$notice       = get_setting('notice');
?>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WireGuard Portal</title>
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
    <h1>ローカルサーバーを<em>外部公開</em></h1>
    <p>自宅・社内の PC で動いている Web サーバーを VPN 経由でインターネットに安全に公開できます。</p>
  </div>

  <?php if ($notice !== ''): ?>
  <!-- 管理者からのお知らせ -->
  <div class="notice-box"><?= message_html($notice) ?></div>
  <?php endif; ?>

  <!-- 発行済みの利用者向けの入口 -->
  <div class="manage-entry">
    <div class="manage-entry-text">
      <strong>発行済みのポートがある方</strong>
      <span>公開方式の変更・設定ファイルの再取得<?= $delete_mode !== 'admin' ? '・削除' : '' ?>はこちら</span>
      <span>管理トークンを忘れた方は、管理者にご連絡ください。</span>
    </div>
    <a class="manage-entry-btn" href="manage.php">設定変更画面を開く →</a>
  </div>

  <!-- 使い方ガイド -->
  <div class="guide">
    <div class="guide-title">使い方</div>
    <div class="guide-steps">
      <div class="guide-step">
        <span class="guide-num">1</span>
        <div>
          <div class="guide-text">公開したい <em>外部ポート番号</em> を入力する</div>
          <div class="guide-sub">例: 11090 → 外部から http://[VPSのIP]:11090 でアクセス可能になります<?php if ($https_ready): ?> (HTTPS を選んだ場合は https://<?= htmlspecialchars(get_setting('https_host')) ?>:11090)<?php endif; ?></div>
          <div class="guide-sub">※ サーバー上で使用中のポートなど、指定できないポート番号があります。エラーになった場合は別の番号をお試しください</div>
          <div class="guide-sub">※ PC 側で待ち受けているポートが 80 以外の場合は <em>転送先ポート</em> も変更してください</div>
        </div>
      </div>
      <div class="guide-step">
        <span class="guide-num">2</span>
        <div>
          <div class="guide-text">「設定を生成する」をクリックする</div>
        </div>
      </div>
      <div class="guide-step">
        <span class="guide-num">3</span>
        <div>
          <div class="guide-text"><em>wg&#x2011;client_XXXX.conf</em> をダウンロードする</div>
          <div class="guide-sub">WireGuard for Windows でこのファイルをインポートします</div>
        </div>
      </div>
      <div class="guide-step">
        <span class="guide-num">4</span>
        <div>
          <div class="guide-text">WireGuard でトンネルを <em>有効化</em> する</div>
          <div class="guide-sub">インポート後、トグルをONにするだけで接続できます</div>
        </div>
      </div>
      <div class="guide-step">
        <span class="guide-num">5</span>
        <div>
          <div class="guide-text">PC の <em>転送先ポート</em> で Web サーバーを起動する</div>
          <div class="guide-sub">外部からのアクセスはこのポートに転送されます (既定: <?= $default_dest_port ?>)</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 生成フォーム -->
  <div class="card">
    <div class="warn-box">
      <span class="warn-icon">⚠</span>
      <span>発行済みのポートの公開方式の変更や設定ファイルの再取得は、<a class="sub-link" href="manage.php">設定変更画面</a>から<strong>管理トークン</strong>を使って行います。</span>
    </div>

    <div class="input-group">
      <div class="input-wrap">
        <label>公開ポート番号 (TCP)</label>
        <input type="number" id="port-input" min="1024" max="65535"
               placeholder="11090" autocomplete="off">
        <div class="error-msg" id="port-error"></div>
      </div>
      <div class="input-wrap">
        <label>転送先ポート (PC 側)</label>
        <input type="number" id="dest-port-input" min="1" max="65535"
               value="<?= $default_dest_port ?>" autocomplete="off">
        <div class="error-msg" id="dest-port-error"></div>
      </div>
    </div>

    <label class="field-label">公開方式</label>
    <div class="mode-group">
      <label class="mode-opt">
        <input type="radio" name="proxy_mode" value="dnat" <?= $default_mode === 'dnat' ? 'checked' : '' ?>> HTTP
      </label>
      <label class="mode-opt<?= $https_ready ? '' : ' disabled' ?>">
        <input type="radio" name="proxy_mode" value="https" <?= $default_mode === 'https' ? 'checked' : '' ?> <?= $https_ready ? '' : 'disabled' ?>> HTTPS
      </label>
    </div>
    <div class="mode-note">
      <?php if ($https_ready): ?>
        HTTPS: <?= message_html($https_note) ?><br>
        方式はあとから設定変更画面で切り替えられます (鍵は変わりません)。
      <?php else: ?>
        HTTPS 公開は管理者が設定していないため選べません。
      <?php endif; ?>
    </div>

    <button class="btn-generate" id="gen-btn" onclick="doGenerate()">
      <span id="btn-label">設定を生成する</span>
    </button>
  </div>

  <!-- 結果エリア -->
  <div id="result-area">

    <!-- Windows クライアント設定 (常に表示) -->
    <div class="result-card">
      <div class="result-header" onclick="togglePanel('panel-win', this)">
        <div class="result-title">
          <span class="badge badge-win">Windows</span>
          クライアント設定
        </div>
        <span class="chevron open">▾</span>
      </div>
      <div class="result-body open" id="panel-win">
        <div class="guide-sub" id="fwd-summary" style="margin:0 0 10px;white-space:pre-line;font-size:12px;line-height:1.7"></div>
        <div class="code-block" id="win-conf-block">
          <button class="copy-btn" onclick="copyBlock('win-conf-block')">コピー</button>
        </div>
        <button class="dl-btn" id="dl-btn" onclick="downloadConf()">↓ wg-client.conf をダウンロード</button>
      </div>
    </div>

    <!-- 管理トークン -->
    <div id="token-notice" class="result-card" style="display:none">
      <div class="result-header" style="cursor:default;background:#fffbeb;border-color:#fde68a">
        <div class="result-title" style="color:#92400e">
          <span class="badge" style="background:#fef3c7;color:#92400e">TOKEN</span>
          管理トークン（この画面を閉じると二度と表示されません）
        </div>
      </div>
      <div class="result-body open" style="display:block">
        <div class="code-block" id="token-value" style="word-break:break-all;user-select:all"></div>
        <p style="font-size:12px;color:#92400e;margin-top:10px;line-height:1.7">
          <a class="sub-link" href="manage.php">設定変更画面</a>で、公開方式の変更や設定ファイルの再取得に使います<?= $delete_mode === 'token' ? '。設定の削除にも必要です' : '' ?>。安全な場所に保存してください。<br>
          なくした場合は、管理者に再発行を依頼してください。
        </p>
      </div>
    </div>

    <!-- 管理者向け詳細 (折りたたみ) -->
    <div>
      <button class="detail-toggle" onclick="toggleDetail(this)">
        <span>詳細・管理者向け情報</span>
        <span class="chevron">▾</span>
      </button>
      <div class="detail-body" id="detail-body" style="display:none">

        <!-- 自動適用結果 -->
        <div id="apply-result" style="display:none"></div>

        <!-- VPS 設定 -->
        <div class="result-card">
          <div class="result-header" onclick="togglePanel('panel-vps', this)">
            <div class="result-title">
              <span class="badge badge-vps">VPS</span>
              サーバー設定 (<?= $wg_iface ?>.conf)
            </div>
            <span class="chevron">▾</span>
          </div>
          <div class="result-body" id="panel-vps">
            <div class="code-block" id="vps-conf-block">
              <button class="copy-btn" onclick="copyBlock('vps-conf-block')">コピー</button>
            </div>
          </div>
        </div>

        <!-- セットアップコマンド -->
        <div class="result-card">
          <div class="result-header" onclick="togglePanel('panel-cmd', this)">
            <div class="result-title">
              <span class="badge badge-cmd">SETUP</span>
              セットアップコマンド
            </div>
            <span class="chevron">▾</span>
          </div>
          <div class="result-body" id="panel-cmd">
            <div class="code-block" id="cmd-block">
              <button class="copy-btn" onclick="copyBlock('cmd-block')">コピー</button>
            </div>
          </div>
        </div>

      </div><!-- /detail-body -->
    </div>

  </div><!-- /result-area -->

  <!-- 設定削除フォーム (admin モードは非表示) -->
  <?php if ($delete_mode !== 'admin'): ?>
  <div class="card">
    <div class="warn-box">
      <span class="warn-icon">⚠</span>
      <span>登録したポート番号を入力すると、その設定を削除できます。<strong>この操作は元に戻せません。</strong></span>
    </div>

    <div class="input-group">
      <div class="input-wrap">
        <label>削除するポート番号</label>
        <input type="number" id="del-port-input" min="1024" max="65535"
               placeholder="11090" autocomplete="off">
        <div class="error-msg" id="del-port-error"></div>
      </div>
    </div>

    <?php if ($delete_mode === 'token'): ?>
    <div class="input-group">
      <div class="input-wrap">
        <label>管理トークン</label>
        <input type="text" id="del-token-input" placeholder="設定生成時に表示されたトークン" autocomplete="off">
        <div class="error-msg" id="del-token-error"></div>
        <div class="field-hint">管理トークンを忘れた方は、管理者にご連絡ください。</div>
      </div>
    </div>
    <?php endif; ?>

    <button class="btn-delete" id="del-btn" onclick="doDelete()">
      <span id="del-btn-label">設定を削除する</span>
    </button>
    <div id="del-result"></div>
  </div>
  <?php endif; ?>

</main>

<footer>
  <a href="admin.php">管理画面</a>
</footer>

<script>
const DELETE_MODE = <?= json_encode($delete_mode) ?>;

let winConfContent = '';
let currentPort    = 0;

async function doGenerate() {
  const input     = document.getElementById('port-input');
  const errEl     = document.getElementById('port-error');
  const destInput = document.getElementById('dest-port-input');
  const destErrEl = document.getElementById('dest-port-error');
  const btn       = document.getElementById('gen-btn');
  const label     = document.getElementById('btn-label');
  const port      = parseInt(input.value, 10);
  const destPort  = parseInt(destInput.value, 10);
  const proxyMode = (document.querySelector('input[name="proxy_mode"]:checked') || {}).value || 'dnat';

  errEl.textContent     = '';
  destErrEl.textContent = '';
  input.classList.remove('error');
  destInput.classList.remove('error');

  if (!input.value || isNaN(port) || port < 1024 || port > 65535) {
    errEl.textContent = '1024〜65535 の範囲で入力してください。';
    input.classList.add('error');
    return;
  }

  if (!destInput.value || isNaN(destPort) || destPort < 1 || destPort > 65535) {
    destErrEl.textContent = '1〜65535 の範囲で入力してください。';
    destInput.classList.add('error');
    return;
  }

  btn.disabled = true;
  label.innerHTML = '<span class="spinner"></span>';

  try {
    const res  = await fetch('generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ port, dest_port: destPort, proxy_mode: proxyMode })
    });
    const json = await res.json();

    if (!res.ok || !json.ok) {
      errEl.textContent = json.error || 'エラーが発生しました。';
      if (json.manage) {
        const a = document.createElement('a');
        a.href = `manage.php?port=${port}`;
        a.className = 'sub-link';
        a.textContent = ' → 設定変更画面へ';
        errEl.appendChild(a);
      }
      input.classList.add('error');
      return;
    }

    const d = json.data;
    winConfContent = d.client_conf;
    currentPort    = d.port;

    setText('win-conf-block', d.client_conf);
    setText('vps-conf-block', d.server_conf);
    setText('cmd-block',      d.setup_cmds);

    document.getElementById('dl-btn').textContent = `↓ wg-client_${d.port}.conf をダウンロード`;
    document.getElementById('fwd-summary').textContent =
      `公開 URL: ${d.public_url} (${d.proxy_mode === 'https' ? 'HTTPS' : 'HTTP'})
` +
      `外部ポート ${d.port} → PC (${d.client_ip}) のポート ${d.dest_port} に転送されます。`;

    // 管理トークン表示
    const tokenNotice = document.getElementById('token-notice');
    document.getElementById('token-value').textContent = d.manage_token || '';
    tokenNotice.style.display = d.manage_token ? 'block' : 'none';

    const applyEl = document.getElementById('apply-result');
    if (d.applied !== null && d.applied !== undefined) {
      const ok  = d.applied.success;
      const out = d.applied.output || '';
      applyEl.innerHTML =
        `<div class="apply-card ${ok ? 'apply-ok' : 'apply-err'}">` +
        `<div class="apply-title">${ok ? '✓ サーバーへの適用が完了しました' : '✗ サーバーへの適用に失敗しました'}</div>` +
        (out ? `<div class="apply-output">${escHtml(out)}</div>` : '') +
        `</div>`;
      applyEl.style.display = 'block';
    } else {
      applyEl.style.display = 'none';
    }

    const area = document.getElementById('result-area');
    area.style.display = 'flex';
    area.style.flexDirection = 'column';
    area.scrollIntoView({ behavior: 'smooth', block: 'start' });

  } catch(e) {
    errEl.textContent = 'ネットワークエラーが発生しました。';
  } finally {
    btn.disabled = false;
    label.textContent = '設定を生成する';
  }
}

function openDetail() {
  const body = document.getElementById('detail-body');
  if (body.style.display === 'none') body.style.display = 'flex';
}

function toggleDetail(btn) {
  const body    = document.getElementById('detail-body');
  const chevron = btn.querySelector('.chevron');
  const isOpen  = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : 'flex';
  chevron.classList.toggle('open', !isOpen);
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function setText(id, text) {
  const el  = document.getElementById(id);
  const btn = el.querySelector('.copy-btn');
  el.textContent = text;
  el.appendChild(btn);
}

function copyBlock(id) {
  const el   = document.getElementById(id);
  const btn  = el.querySelector('.copy-btn');
  const text = el.textContent.replace(/^(コピー|完了)/, '').trim();
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = '完了';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'コピー'; btn.classList.remove('copied'); }, 1800);
  });
}

function downloadConf() {
  if (!winConfContent) return;
  const blob = new Blob([winConfContent], { type: 'text/plain' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = currentPort ? `wg-client_${currentPort}.conf` : 'wg-client.conf';
  a.click();
}

function togglePanel(panelId, header) {
  const body    = document.getElementById(panelId);
  const chevron = header.querySelector('.chevron');
  const open    = body.classList.toggle('open');
  chevron.classList.toggle('open', open);
}

['port-input', 'dest-port-input'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => {
    if (e.key === 'Enter') doGenerate();
  });
});

async function doDelete() {
  const input    = document.getElementById('del-port-input');
  const errEl    = document.getElementById('del-port-error');
  const resultEl = document.getElementById('del-result');
  const btn      = document.getElementById('del-btn');
  const label    = document.getElementById('del-btn-label');
  const port     = parseInt(input.value, 10);

  errEl.textContent = '';
  resultEl.innerHTML = '';
  input.classList.remove('error');

  if (!input.value || isNaN(port) || port < 1024 || port > 65535) {
    errEl.textContent = '1024〜65535 の範囲で入力してください。';
    input.classList.add('error');
    return;
  }

  // token モードのときトークン入力を検証
  let token = '';
  if (DELETE_MODE === 'token') {
    const tokenInput = document.getElementById('del-token-input');
    token = tokenInput ? tokenInput.value.trim() : '';
    const tokenErrEl = document.getElementById('del-token-error');
    if (tokenErrEl) tokenErrEl.textContent = '';
    if (!token) {
      if (tokenErrEl) tokenErrEl.textContent = '管理トークンを入力してください。';
      return;
    }
  }

  if (!confirm(`ポート ${port} の設定を削除しますか？\nこの操作は元に戻せません。`)) return;

  btn.disabled = true;
  label.innerHTML = '<span class="spinner"></span>';

  try {
    const res  = await fetch('delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ port, token })
    });
    const json = await res.json();

    if (!res.ok || !json.ok) {
      resultEl.innerHTML = `<div class="del-err">${escHtml(json.error || 'エラーが発生しました。')}</div>`;
      return;
    }

    resultEl.innerHTML = `<div class="del-ok">ポート ${json.port} の設定を削除しました。</div>`;
    input.value = '';
    const tokenInput = document.getElementById('del-token-input');
    if (tokenInput) tokenInput.value = '';
  } catch(e) {
    resultEl.innerHTML = `<div class="del-err">ネットワークエラーが発生しました。</div>`;
  } finally {
    btn.disabled = false;
    label.textContent = '設定を削除する';
  }
}

document.getElementById('del-port-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') doDelete();
});
</script>
</body>
</html>
