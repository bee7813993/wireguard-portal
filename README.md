# WireGuard Portal

自宅・社内の Web サーバーを WireGuard VPN 経由で外部公開するための管理ポータルです。  
利用者はポート番号を入力するだけで WireGuard 設定ファイルを生成でき、VPS 側の設定は自動で適用されます。

---

## 構成

```
[外部ブラウザ]
      ↓ TCP :XXXX
[VPS (nginx + PHP-FPM)]
      ↓ WireGuard トンネル (UDP :51821)
[社内 PC (WireGuard for Windows)]
      ↓ localhost
[Web サーバー (XAMPP 等) :80]
```

- VPS 上の nginx が PHP-FPM で本ポータルを提供
- 利用者がポート番号を入力 → WireGuard 設定ファイルを生成・自動適用
- iptables DNAT で外部ポートを WireGuard トンネル内の PC ポート 80 に転送

---

## 動作要件

| 項目 | 内容 |
|------|------|
| VPS OS | Ubuntu 22.04 / Debian 12 推奨 |
| PHP | 8.1 以上 (php-fpm, php-sqlite3, php-sodium) |
| Web サーバー | nginx |
| WireGuard | `wireguard-tools` パッケージ |
| 権限 | www-data が sudo で wg/iptables を実行できること |

---

## インストール

### 1. パッケージのインストール

```bash
sudo apt update
sudo apt install nginx php8.1-fpm php8.1-sqlite3 php8.1-sodium wireguard-tools -y
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
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -S FORWARD
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -D FORWARD *
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -S POSTROUTING
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -t nat -D POSTROUTING *
```

```bash
sudo chmod 440 /etc/sudoers.d/wireguard-portal
sudo visudo -c  # 構文チェック
```

### 6. IP フォワーディングの有効化

```bash
echo "net.ipv4.ip_forward=1" | sudo tee -a /etc/sysctl.conf
sudo sysctl -p
```

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
| WireGuard サブネット | VPN 内部ネットワーク（例: `10.100.10`） |
| WireGuard インターフェース名 | `wg0` が使用中なら `wg1` や `wg10` など別名を指定 |
| 設定生成時に自動適用 | 有効にすると生成時に自動で `wg-quick` を適用する |
| パスワード | **`changeme` から必ず変更すること** |

---

## ファイル構成

```
wireguard-portal/
├── index.php          # トップ画面（利用者向け）
├── admin.php          # 管理画面
├── generate.php       # 設定生成 API
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

**「WireGuard 停止 & iptables クリア」ボタン**で、WireGuard インターフェースを停止し、設定されたすべての iptables ルール（DNAT / FORWARD / MASQUERADE）を削除できます。使用をやめる場合や、iptables ルールが残留している場合に使用してください。

### 発行済みポート一覧

発行されたポートの一覧と削除ができます。ポートを削除すると、自動適用が有効な場合は WireGuard 設定が即座に更新されます。

### アクティビティログ

直近 200 行のログを管理画面から確認できます。ログファイルは `data/portal.log` に保存されます。

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
- 本番環境では HTTPS を必ず使用してください（秘密鍵がネットワークを流れます）。

---

## エンドユーザー向けガイド

利用者への案内は `docs/利用ガイド.md` を GitHub Wiki にコピーして使用してください。
