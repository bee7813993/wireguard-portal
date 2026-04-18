<!DOCTYPE html>
<?php
require_once __DIR__ . '/config.php';
$wg_iface   = htmlspecialchars(get_setting('wg_interface') ?: 'wg0');
$auto_apply = get_setting('auto_apply') === '1';
?>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WireGuard Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+JP:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:      #0d0f12;
  --surface: #13161b;
  --border:  #1e2330;
  --border2: #2a3044;
  --accent:  #4ade80;
  --accent2: #22d3ee;
  --text:    #e2e8f0;
  --muted:   #64748b;
  --mono:    'IBM Plex Mono', monospace;
  --sans:    'IBM Plex Sans JP', sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; }
body {
  font-family: var(--sans);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ---- Header ---- */
header {
  border-bottom: 1px solid var(--border);
  padding: 1.25rem 2rem;
  display: flex;
  align-items: center;
  gap: 12px;
}
.logo-mark {
  width: 28px; height: 28px;
  border: 1.5px solid var(--accent);
  border-radius: 6px;
  display: grid;
  place-items: center;
}
.logo-mark svg { width: 16px; height: 16px; }
.logo-text { font-family: var(--mono); font-size: 14px; letter-spacing: .08em; color: var(--text); }
.logo-text span { color: var(--accent); }

/* ---- Main layout ---- */
main {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 4rem 1.5rem 3rem;
}
.hero {
  text-align: center;
  margin-bottom: 3rem;
}
.hero h1 {
  font-family: var(--mono);
  font-size: clamp(1.5rem, 4vw, 2.25rem);
  font-weight: 500;
  letter-spacing: -.01em;
  line-height: 1.2;
  margin-bottom: .75rem;
}
.hero h1 em {
  font-style: normal;
  color: var(--accent);
}
.hero p {
  font-size: 14px;
  color: var(--muted);
  line-height: 1.7;
  max-width: 42ch;
  margin: 0 auto;
}

/* ---- Card ---- */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  width: 100%;
  max-width: 540px;
  padding: 2rem;
  margin-bottom: 1.5rem;
}

/* ---- Form ---- */
.input-group {
  display: flex;
  gap: 10px;
  margin-bottom: 1rem;
}
.input-wrap {
  flex: 1;
  position: relative;
}
.input-wrap label {
  display: block;
  font-size: 11px;
  font-family: var(--mono);
  color: var(--muted);
  letter-spacing: .06em;
  text-transform: uppercase;
  margin-bottom: 8px;
}
.input-wrap input {
  width: 100%;
  background: var(--bg);
  border: 1px solid var(--border2);
  border-radius: 8px;
  padding: 10px 14px;
  font-family: var(--mono);
  font-size: 16px;
  color: var(--text);
  outline: none;
  transition: border-color .15s;
}
.input-wrap input:focus { border-color: var(--accent); }
.input-wrap input.error { border-color: #f87171; }
.error-msg {
  font-size: 12px;
  color: #f87171;
  margin-top: 6px;
  min-height: 16px;
  font-family: var(--mono);
}

.btn-generate {
  width: 100%;
  padding: 12px;
  background: var(--accent);
  color: #0d1a12;
  font-family: var(--mono);
  font-size: 14px;
  font-weight: 500;
  letter-spacing: .04em;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: opacity .15s, transform .1s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.btn-generate:hover { opacity: .88; }
.btn-generate:active { transform: scale(.98); }
.btn-generate:disabled { opacity: .4; cursor: not-allowed; transform: none; }

/* ---- Warning ---- */
.warn-box {
  background: rgba(234,179,8,.06);
  border: 1px solid rgba(234,179,8,.25);
  border-radius: 8px;
  padding: 12px 14px;
  font-size: 13px;
  color: #fcd34d;
  line-height: 1.6;
  margin-bottom: 1.5rem;
  display: flex;
  gap: 10px;
}
.warn-icon { flex-shrink: 0; margin-top: 1px; }

/* ---- Apply status ---- */
.apply-card {
  border-radius: 10px;
  padding: 14px 18px;
  font-family: var(--mono);
  font-size: 12px;
  line-height: 1.6;
}
.apply-ok  { background: rgba(74,222,128,.08); border: 1px solid rgba(74,222,128,.25); color: var(--accent); }
.apply-err { background: rgba(248,113,113,.08); border: 1px solid rgba(248,113,113,.25); color: #f87171; }
.apply-title { font-size: 13px; font-weight: 500; margin-bottom: 6px; }
.apply-output { white-space: pre-wrap; color: var(--muted); margin-top: 6px; font-size: 11px; }

/* ---- Result panels ---- */
#result-area { width: 100%; max-width: 540px; display: none; flex-direction: column; gap: 1.25rem; }

.result-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.result-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  user-select: none;
}
.result-header:hover { background: rgba(255,255,255,.02); }
.result-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-family: var(--mono);
  font-size: 13px;
  font-weight: 500;
}
.badge {
  font-size: 10px;
  padding: 2px 8px;
  border-radius: 4px;
  font-family: var(--mono);
  letter-spacing: .04em;
}
.badge-win  { background: rgba(34,211,238,.12); color: var(--accent2); border: 1px solid rgba(34,211,238,.2); }
.badge-vps  { background: rgba(74,222,128,.12); color: var(--accent);  border: 1px solid rgba(74,222,128,.2); }
.badge-cmd  { background: rgba(167,139,250,.12); color: #c4b5fd;       border: 1px solid rgba(167,139,250,.2); }
.chevron { transition: transform .2s; color: var(--muted); font-size: 12px; }
.chevron.open { transform: rotate(180deg); }

.result-body { display: none; padding: 16px 18px; }
.result-body.open { display: block; }

.code-block {
  position: relative;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 14px 16px;
  font-family: var(--mono);
  font-size: 12px;
  line-height: 1.7;
  color: var(--text);
  white-space: pre;
  overflow-x: auto;
}
.copy-btn {
  position: absolute;
  top: 8px; right: 8px;
  padding: 3px 10px;
  background: var(--surface);
  border: 1px solid var(--border2);
  border-radius: 4px;
  color: var(--muted);
  font-family: var(--mono);
  font-size: 10px;
  cursor: pointer;
  transition: color .1s, border-color .1s;
}
.copy-btn:hover { color: var(--text); border-color: var(--muted); }
.copy-btn.copied { color: var(--accent); border-color: var(--accent); }

.dl-btn {
  margin-top: 12px;
  width: 100%;
  padding: 9px;
  background: transparent;
  border: 1px solid var(--border2);
  border-radius: 8px;
  color: var(--accent2);
  font-family: var(--mono);
  font-size: 12px;
  cursor: pointer;
  transition: border-color .15s, background .15s;
}
.dl-btn:hover { border-color: var(--accent2); background: rgba(34,211,238,.05); }

.step-list {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.step {
  display: flex;
  gap: 10px;
  font-size: 13px;
  color: var(--muted);
  line-height: 1.5;
}
.step-num {
  width: 20px; height: 20px;
  border-radius: 50%;
  border: 1px solid var(--border2);
  display: grid;
  place-items: center;
  font-family: var(--mono);
  font-size: 10px;
  color: var(--muted);
  flex-shrink: 0;
  margin-top: 1px;
}

/* ---- Spinner ---- */
.spinner {
  width: 16px; height: 16px;
  border: 2px solid rgba(13,26,18,.4);
  border-top-color: #0d1a12;
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ---- Footer ---- */
footer {
  text-align: center;
  padding: 1.5rem;
  font-size: 12px;
  color: var(--muted);
  border-top: 1px solid var(--border);
  font-family: var(--mono);
}
footer a { color: var(--muted); text-decoration: none; }
footer a:hover { color: var(--text); }
</style>
</head>
<body>

<header>
  <div class="logo-mark">
    <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="8" cy="4" r="2" stroke="#4ade80" stroke-width="1.5"/>
      <circle cx="3" cy="12" r="2" stroke="#4ade80" stroke-width="1.5"/>
      <circle cx="13" cy="12" r="2" stroke="#4ade80" stroke-width="1.5"/>
      <line x1="8" y1="6" x2="3" y2="10" stroke="#4ade80" stroke-width="1"/>
      <line x1="8" y1="6" x2="13" y2="10" stroke="#4ade80" stroke-width="1"/>
    </svg>
  </div>
  <span class="logo-text">WireGuard <span>Portal</span></span>
</header>

<main>
  <div class="hero">
    <h1>トンネルを<em>開通</em>する</h1>
    <p>公開ポート番号を入力すると、Windows 用 WireGuard 設定と VPS 設定を自動生成します。</p>
  </div>

  <div class="card">
    <div class="warn-box">
      <span class="warn-icon">⚠</span>
      <span>同じポートを再入力すると<strong>鍵と設定が再発行</strong>され、以前の設定は無効になります。</span>
    </div>

    <div class="input-group">
      <div class="input-wrap">
        <label>公開ポート番号 (TCP)</label>
        <input type="number" id="port-input" min="1024" max="65535"
               placeholder="8080" autocomplete="off">
        <div class="error-msg" id="port-error"></div>
      </div>
    </div>

    <button class="btn-generate" id="gen-btn" onclick="doGenerate()">
      <span id="btn-label">設定を生成する</span>
    </button>
  </div>

  <div id="result-area">

    <div class="step-list" style="width:100%;max-width:540px;margin-bottom:.5rem">
      <div class="step"><span class="step-num">1</span><span>「Windows クライアント設定」を .conf としてダウンロードし、WireGuard for Windows でインポートする。</span></div>
      <?php if ($auto_apply): ?>
      <div class="step"><span class="step-num">2</span><span>VPS サーバー設定は自動適用されます。適用結果を確認してください。</span></div>
      <div class="step"><span class="step-num">3</span><span>Windows 側でトンネルを有効化する。</span></div>
      <?php else: ?>
      <div class="step"><span class="step-num">2</span><span>「VPS サーバー設定」を /etc/wireguard/<?= $wg_iface ?>.conf に保存する。</span></div>
      <div class="step"><span class="step-num">3</span><span>「セットアップコマンド」を VPS で順番に実行する。</span></div>
      <div class="step"><span class="step-num">4</span><span>Windows 側でトンネルを有効化する。</span></div>
      <?php endif; ?>
    </div>

    <!-- Windows クライアント設定 -->
    <div class="result-card">
      <div class="result-header" onclick="togglePanel('panel-win', this)">
        <div class="result-title">
          <span class="badge badge-win">Windows</span>
          クライアント設定
        </div>
        <span class="chevron open">▾</span>
      </div>
      <div class="result-body open" id="panel-win">
        <div class="code-block" id="win-conf-block">
          <button class="copy-btn" onclick="copyBlock('win-conf-block')">コピー</button>
        </div>
        <button class="dl-btn" onclick="downloadConf()">↓ wg-client.conf をダウンロード</button>
      </div>
    </div>

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

  </div><!-- /result-area -->
</main>

<footer>
  <a href="admin.php">管理画面</a>
</footer>

<script>
let winConfContent = '';

async function doGenerate() {
  const input = document.getElementById('port-input');
  const errEl = document.getElementById('port-error');
  const btn   = document.getElementById('gen-btn');
  const label = document.getElementById('btn-label');
  const port  = parseInt(input.value, 10);

  errEl.textContent = '';
  input.classList.remove('error');

  if (!input.value || isNaN(port) || port < 1024 || port > 65535) {
    errEl.textContent = '1024〜65535 の範囲で入力してください。';
    input.classList.add('error');
    return;
  }

  btn.disabled = true;
  label.innerHTML = '<span class="spinner"></span>';

  try {
    const res  = await fetch('generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ port })
    });
    const json = await res.json();

    if (!res.ok || !json.ok) {
      errEl.textContent = json.error || 'エラーが発生しました。';
      input.classList.add('error');
      return;
    }

    const d = json.data;
    winConfContent = d.client_conf;

    setText('win-conf-block', d.client_conf);
    setText('vps-conf-block', d.server_conf);
    setText('cmd-block',      d.setup_cmds);

    // 自動適用結果の表示
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
    label.textContent = '設定を再生成する';
  }
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
  a.download = 'wg-client.conf';
  a.click();
}

function togglePanel(panelId, header) {
  const body    = document.getElementById(panelId);
  const chevron = header.querySelector('.chevron');
  const open    = body.classList.toggle('open');
  chevron.classList.toggle('open', open);
}

document.getElementById('port-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') doGenerate();
});
</script>
</body>
</html>
