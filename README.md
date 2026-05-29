# WireGuard Portal

自宅・社内の Web サーバーを WireGuard VPN 経由で外部公開するための管理ポータルです。  
利用者はポート番号を入力するだけで WireGuard 設定ファイルを生成でき、VPS 側の設定は自動で適用されます。

---

## 構成

```
[外部ブラウザ] (IPv4 / IPv6)
      ↓ TCP :XXXX
[VPS (nginx + PHP-FPM)]
      ↓ WireGuard トンネル (UDP :51821, IPv4 + IPv6)
[社内 PC (WireGuard for Windows/Linux/macOS)]
      ↓ localhost
[Web サーバー (XAMPP 等) :80]
```

- VPS 上の nginx が PHP-FPM で本ポータルを提供
- 利用者がポート番号を入力 → WireGuard 設定ファイルを生成・自動適用
- iptables/ip6tables DNAT で外部ポートを WireGuard トンネル内の PC ポート 80 に転送
- IPv4・IPv6 の両方のトラフィックに対応

---

## 動作要件

| 項目 | 内容 |
|------|------|
| VPS OS | Ubuntu 22.04 / Debian 12 推奨 |
| PHP | 8.1 以上 (php-fpm, php-sqlite3, php-sodium) |
| Web サーバー | nginx |
| WireGuard | `wireguard-tools` パッケージ |
| iptables/ip6tables | `iptables` パッケージ |
| IPv6 | Linux カーネルの IPv6 フォワーディング + ip6table_nat モジュール（オプション） |
| 権限 | www-data が sudo で wg/iptables/ip6tables を実行できること |

---

## インストール

### 1. パッケージのインストール

```bash
sudo apt update
sudo apt install nginx php8.1-fpm php8.1-sqlite3 php8.1-sodium wireguard-tools iptables -y
```

### 2. ファイルの配置

```bash
sudo cp -r wireguard-portal /var/www/html/
sudo chown -R www-data:www-data /var/www/html/wireguard-portal
sudo chmod -R 750 /var/www/html/wireguard-portal
```

### 3. nginx の設定

`/etc/nginx/sites-available/wireguard-portal`:

```nginx
server {
    listen 80;
    server_name your-domain.example.com;
    root /var/www/html/wireguard-portal;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    location ~ /data/ {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/wireguard-portal /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 4. WireGuard ディレクトリの権限設定

```bash
sudo chown root:www-data /etc/wireguard
sudo chmod 750 /etc/wireguard
```

### 5. sudo 権限の設定

`/etc/sudoers.d/wireguard-portal` を作成:

```
www-data ALL=(ALL) NOPASSWD: /usr/bin/wg show *
www-data ALL=(ALL) NOPASSWD: /usr/bin/wg-quick up *
www-data ALL=(ALL) NOPASSWD: /usr/bin/wg-quick down *
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /etc/wireguard/*
www-data ALL=(ALL) NOPASSWD: /bin/chmod 600 /etc/wireguard/*
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -S PREROUTING
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -D PREROUTING *
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -A PREROUTING *
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -S FORWARD
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -D FORWARD *
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -A FORWARD *
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -S POSTROUTING
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -D POSTROUTING *
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -A POSTROUTING *
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -t nat -S PREROUTING
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -t nat -D PREROUTING *
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -t nat -A PREROUTING *
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -S FORWARD
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -D FORWARD *
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -A FORWARD *
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -t nat -S POSTROUTING
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -t nat -D POSTROUTING *
www-data ALL=(ALL) NOPASSWD: /sbin/ip6tables -t nat -A POSTROUTING *
```

```bash
sudo chmod 440 /etc/sudoers.d/wireguard-portal
sudo visudo -c  # 構文チェック
```

### 6. IP フォワーディングの有効化（IPv4 & IPv6）

```bash
echo "net.ipv4.ip_forward=1" | sudo tee -a /etc/sysctl.conf
echo "net.ipv6.conf.all.forwarding=1" | sudo tee -a /etc/sysctl.conf
sudo sysctl -p
```

### 6.5. ip6table_nat モジュールの有効化（IPv6 NAT が必要な場合）

```bash
sudo modprobe ip6table_nat
echo "ip6table_nat" | sudo tee -a /etc/modules
```

> **注：** 既存の WireGuard 設定を IPv6 対応にする場合は、管理画面の「IPv6 対応で全ポートを再適用」ボタンを押す前に、このモジュールのロードを完了してください。

### 7. ファイアウォールの開放

```bash
sudo ufw allow 80/tcp       # ポータル
sudo ufw allow 51821/udp    # WireGuard (wg_port に合わせて変更)
```

---

## 初期設定（管理画面）

ブラウザで `http://[VPS-IP]/wireguard-portal/admin.php` にアクセスし、初期パスワード `changeme` でログイン後、以下を設定してください。

| 設定項目 | 説明 |
|---------|------|
| VPS グローバル IP | クライアントが接続する VPS の IP またはドメイン |
| WireGuard ポート (UDP) | VPS の WireGuard 待ち受けポート（デフォルト: `51820`） |
| 外向き NIC 名 | VPS のネットワークインターフェース名（`ip a` で確認。例: `ens3`, `eth0`） |
| WireGuard サブネット | VPN 内部ネットワーク（例: `10.100.10`。先頭3オクテット） |
| WireGuard IPv6 サブネット | VPN 内部の IPv6 ネットワーク（例: `fd00::`。ULA推奨） |
| WireGuard インターフェース名 | `wg0` が使用中なら `wg1` や `wg10` など別名を指定 |
| 設定生成時に自動適用 | 有効にすると生成時に自動で `wg-quick` を適用する |
| ユーザー削除モード | 一般ユーザーが設定を削除できる方法を選択する（後述） |
| パスワード | **`changeme` から必ず変更すること** |

---

## ファイル構成

```
wireguard-portal/
├── index.php          # トップ画面（利用者向け）
├── admin.php          # 管理画面
├── generate.php       # 設定生成 API
├── delete.php         # 設定削除 API
├── wg_manager.php     # WireGuard 設定生成・適用ロジック
├── config.php         # DB・共通ユーティリティ
├── data/
│   ├── wg_portal.sqlite   # SQLite DB（自動生成）
│   └── portal.log         # アクティビティログ（自動生成）
└── docs/
    └── 利用ガイド.md       # エンドユーザー向けガイド（Wiki 用）
```

---

## 管理画面の機能

### システム設定

VPS 設定・WireGuard パラメーターを変更できます。変更は次回の設定生成から反映されます。

### WireGuard 制御

#### 「IPv6 対応で全ポートを再適用」ボタン

既存の全ポートに IPv6 ルール（ip6tables）を追加し、サーバー設定を更新します。新しく生成する設定に IPv6 アドレスを含めたい場合に使用します。

実行前に VPS 側で以下を確認してください：
- IPv6 フォワーディングが有効：`sudo sysctl net.ipv6.conf.all.forwarding`（1 なら OK）
- ip6table_nat モジュールがロード：`sudo modprobe ip6table_nat`

#### 「WireGuard 停止 & iptables クリア」ボタン

WireGuard インターフェースを停止し、設定されたすべての iptables・ip6tables ルール（DNAT / FORWARD / MASQUERADE）を削除できます。使用をやめる場合や、iptables ルールが残留している場合に使用してください。

### 発行済みポート一覧

発行されたポートの一覧と削除ができます。各ポートの「設定を表示」ボタンで、IPv6 対応済みのクライアント設定を確認・ダウンロードできます。

ポートを削除すると、自動適用が有効な場合は WireGuard 設定が即座に更新されます。管理者はモードにかかわらず常に削除できます。

### アクティビティログ

直近 200 行のログを管理画面から確認できます。ログファイルは `data/portal.log` に保存されます。

---

## ユーザー削除モード

一般ユーザーが自身の設定を削除できる方法を3つから選択できます。

| モード | 挙動 |
|--------|------|
| `none` | ポート番号を入力するだけで削除可（認証なし）|
| `token` | 設定生成時に発行される削除トークンが必要 |
| `admin` | 管理者のみ削除可。一般ユーザーの削除フォームを非表示にする |

デフォルトは `none` です。

#### `token` モードの動作

1. 「設定を生成する」を押すと、ページ上に**削除トークン**が一度だけ表示されます
2. ユーザーはトークンを安全な場所に保存しておきます
3. 削除時にポート番号とトークンの両方を入力することで削除できます
4. 同じポートで設定を再生成すると、新しいトークンが発行されて旧トークンは無効になります

> **注意（`none` モードの場合）:** ポート番号を知っていれば誰でも削除できます。信頼できるユーザーのみがポータルにアクセスできる環境での利用を前提としています。

---

## 既存 wg0 と共存する場合

`wg0` がすでに使用中の場合は、管理画面で以下を変更してください。

1. **WireGuard インターフェース名** → `wg1` や `wg10` など
2. **WireGuard ポート (UDP)** → `51821` など未使用のポート
3. ファイアウォールを開放: `sudo ufw allow [新しいポート]/udp`

---

## セキュリティ注意事項

- 管理画面のパスワードは初期値 `changeme` から**必ず変更**してください。
- `data/` ディレクトリへの外部アクセスは nginx 設定で遮断してください（上記設定に含まれています）。
- 本ポータルはポート番号を知っていれば誰でも設定を生成できます。信頼できるネットワーク内、または認証付きのリバースプロキシ配下での運用を推奨します。
- ユーザー削除モードが `none` の場合、ポート番号を知っていれば誰でも他人の設定も削除できます。不特定多数がアクセスできる環境では `token` または `admin` モードを選択してください。
- 本番環境では HTTPS を必ず使用してください（秘密鍵がネットワークを流れます）。

---

## エンドユーザー向けガイド

利用者への案内は `docs/利用ガイド.md` を GitHub Wiki にコピーして使用してください。

---

## 更新履歴

### v1.1.0 (2026-05-29) — IPv6 対応

**新機能**
- **IPv6 サポート**: IPv4・IPv6 の両方のポート転送に対応
  - WireGuard に IPv6 アドレス（ULA: `fd00::/64` など）を割り当て可能
  - iptables と ip6tables を並行実装し、IPv4・IPv6 トラフィックを同時処理
  - 既存の IPv4 ルールには影響なし

- **管理画面の拡張**:
  - IPv6 サブネット設定フィールド追加
  - 「IPv6 対応で全ポートを再適用」ボタン（既存ポートを一括 IPv6 対応化）
  - ポート一覧に「設定を表示」ボタン（クライアント設定をモーダルで確認）
  - 事前確認コマンドを折りたたみで表示

- **セットアップ自動化**:
  - セットアップコマンドに IPv6 フォワーディング・ip6table_nat モジュール設定を追加

**修正**
- admin.php の JSON レスポンス生成を PHP ブロックの最初に移動（HTML 出力前に JSON を返すよう修正）
- sudo 権限設定に ip6tables 関連コマンドを追加

**詳細**
- [IPv6 実装コミット](https://github.com/bee7813993/wireguard-portal/commit/8cab606)
- [管理画面 UI 追加](https://github.com/bee7813993/wireguard-portal/commit/4142bf5)
- [セットアップ情報追加](https://github.com/bee7813993/wireguard-portal/commit/db6cc1a)

---

### v1.0.0 (初版)

- WireGuard ポート転送ポータル（IPv4 のみ）
- 管理画面・ユーザーポータル
- iptables DNAT / FORWARD / MASQUERADE 自動化
- SQLite ベースの設定管理
