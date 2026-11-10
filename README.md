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
[Web サーバー (XAMPP 等) :80 など]
```

- VPS 上の nginx が PHP-FPM で本ポータルを提供
- 利用者がポート番号を入力 → WireGuard 設定ファイルを生成・自動適用
- iptables/ip6tables DNAT で外部ポートを WireGuard トンネル内の PC ポート（既定 80・変更可）に転送
- ポートごとに公開方式を選べる: **HTTP**（DNAT でそのまま転送）または **HTTPS**（VPS の nginx が TLS を終端し、PC へは HTTP で転送）
- 利用者は発行時に表示される**管理トークン**で、あとから公開方式の変更・設定ファイルの再取得・削除ができる（鍵は作り直さない）
- IPv4・IPv6 の両方のトラフィックに対応
- ポータルが作成する iptables ルールには `--comment wgportal` が付与され、手動で追加したルールとは区別される

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

> **v1.2.0 以前から更新する場合:** ポータルの iptables ルールに `-m comment --comment wgportal` が付くようになりましたが、上記の `-D` / `-A` 行は末尾がワイルドカード `*` で、sudo は引数列全体を1つの文字列として照合するため、追加された引数もそのまま許可されます。**sudoers の変更は不要です。**
> `-S` 行（`-t nat -S PREROUTING` など）はワイルドカードを持ちませんが、ポータルは引数を追加せずそのまま実行するため、こちらも変更は不要です。

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

> **注：** 既存の WireGuard 設定を IPv6 対応にする場合は、管理画面の「全ポートを再適用」ボタンを押す前に、このモジュールのロードを完了してください。

### 7. ファイアウォールの開放

```bash
sudo ufw allow 80/tcp       # ポータル
sudo ufw allow 51821/udp    # WireGuard (wg_port に合わせて変更)
```

### 8. HTTPS 公開の準備（使う場合のみ）

HTTPS の公開方式を使う場合だけ必要です。VPS の nginx に、ポータルのホスト名の証明書（Let's Encrypt など）が入っていることが前提です。

ポータルはポートごとの nginx 設定ファイル（`https-<ポート>.conf`）を専用のディレクトリに書き込みます。このディレクトリを www-data の持ち物にしておくことで、ファイルの書き込み・削除に sudo を使いません。

```bash
# ポートごとの設定ファイルを置くディレクトリ (管理画面の「nginx 設定の置き場所」と合わせる)
sudo mkdir -p /etc/nginx/wgportal.d
sudo chown www-data:www-data /etc/nginx/wgportal.d
sudo chmod 755 /etc/nginx/wgportal.d

# nginx から読み込む (http ブロックに include される conf.d に 1 行だけ置く)
echo 'include /etc/nginx/wgportal.d/*.conf;' | sudo tee /etc/nginx/conf.d/wgportal.conf
sudo nginx -t && sudo systemctl reload nginx
```

`/etc/sudoers.d/wireguard-portal` に次の 2 行を追加します（パスは `command -v nginx systemctl` で確認してください）。

```
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload nginx
```

```bash
sudo visudo -c  # 構文チェック
```

HTTPS のポートは nginx が VPS 上で直接待ち受ける（DNAT と違い INPUT チェーンを通る）ため、ufw を使っている場合は公開するポートを開けてください。

```bash
sudo ufw allow 11000:12000/tcp   # 例: 許可ポートの範囲に合わせる
```

最後に管理画面の「HTTPS 公開 (nginx)」で **HTTPS のホスト名** を入力します。ホスト名が空のあいだ、利用者は HTTPS を選べません。HTTPS のポートがある状態でホスト名や証明書のパスを変えたときは、「全ポートを再適用」を押して nginx 設定を書き直してください。

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
| 転送先ポートの既定値 | DNAT の宛先となる PC 側の待ち受けポート（既定: `80`）。トップ画面フォームの初期値になり、利用者が個別に変更できる |
| 設定生成時に自動適用 | 有効にすると生成・変更・削除のたびに自動でサーバーへ反映する（WireGuard / iptables / nginx） |
| ユーザー削除モード | 一般ユーザーが設定を削除できる方法を選択する（後述） |
| 使用中ポートの自動ブロック | サーバーで LISTEN 中の TCP ポートへの設定生成を拒否する（デフォルト: 有効） |
| 禁止ポート | 設定生成を禁止するポートのリスト（例: `2222, 8000-8100`） |
| 許可ポート | 指定した場合、このリストのポートのみ設定生成を許可（例: `11000-12000`） |
| HTTPS のホスト名 | HTTPS 公開に使うホスト名（例: `portal.example.com`）。空なら HTTPS は選べない |
| 証明書 / 秘密鍵のパス | 省略時は `/etc/letsencrypt/live/<ホスト名>/fullchain.pem` / `privkey.pem` |
| nginx 設定の置き場所 | ポートごとの nginx 設定を書き込むディレクトリ（既定: `/etc/nginx/wgportal.d`） |
| 新規発行時の公開方式の既定値 | トップ画面で最初に選ばれている方式（既定: HTTP） |
| HTTPS の注意書き | HTTPS の選択肢の下に表示する文（PC 側のアプリに必要な対応など） |
| パスワード | **`changeme` から必ず変更すること** |

---

## ファイル構成

```
wireguard-portal/
├── index.php          # トップ画面（利用者向け）
├── manage.php         # 設定変更画面（利用者向け・管理トークンで操作）
├── portal.css         # 利用者向け画面の共通スタイル
├── admin.php          # 管理画面
├── generate.php       # 設定生成 API
├── delete.php         # 設定削除 API
├── wg_manager.php     # WireGuard / nginx 設定の生成・適用ロジック
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

### ポート制限

設定生成時にポート番号を検査し、既存サービスのポートを WireGuard の転送ルール（iptables DNAT）が乗っ取ってしまう事故を防ぎます。DNAT ルールは指定ポート宛の TCP 通信をすべてトンネル先へ転送するため、SSH や Web サーバーが動作中のポートに設定を生成すると、そのサービスへ外部からアクセスできなくなります。

新規ポートの発行時は以下の順でチェックされ、いずれかに該当するとエラーメッセージを返して生成を拒否します。発行済みのポートはトップ画面から作り直せません（設定変更画面を案内します）。

| チェック | 内容 |
|---------|------|
| 範囲 | `1024`〜`65535` 以外は拒否（well-known ポートは常に保護） |
| 許可ポート | リストが設定されている場合、リスト外のポートを拒否 |
| 禁止ポート | リストに含まれるポートを拒否 |
| 予約ポート | WireGuard ポート（`wg_port`）とポータル自身のポートを拒否 |
| 使用中ポート | サーバー上で LISTEN 中の TCP ポートを拒否（`ss` / `netstat` で検出） |

- 使用中ポートの検出は `ss -tlnH`（なければ `netstat -tln`）を使用します。sudo は不要です。コマンドが使えない環境や PHP の `exec` が無効な環境ではこのチェックはスキップされ、ログに WARN が記録されます。
- ループバック（`127.0.0.1` / `::1`）のみで待ち受けるサービスは、外部 NIC を対象とする DNAT と競合しないため検出の対象外です（内部専用サービスの存在がエラーメッセージから漏れることもありません）。
- 削除にはポート制限を適用しません（誤って衝突したポートも削除で復旧できます）。
- 許可ポートは基本範囲（`1024`〜`65535`）と組み合わせて適用されます。1024 未満のみのリストは全ポートが生成不能になるため、保存時に拒否されます。
- 管理画面には現在 LISTEN 中のポート一覧（ループバック専用を除く）が参考表示されます。
- 一時的に停止しているサービスのポートは LISTEN に現れず検出できません。確実に保護したいポートは「禁止ポート」に登録してください。

### WireGuard 制御

#### 「全ポートを再適用」ボタン

全ポートを含む WireGuard 設定（IPv4 / IPv6 のルール付き）と、HTTPS のポートの nginx 設定を書き直して反映します。旧版で「IPv6 対応で全ポートを再適用」だったボタンです。`wg-quick down` → `up` を行うため、全トンネルが数秒切れます。

実行前に VPS 側で以下を確認してください：
- IPv6 フォワーディングが有効：`sudo sysctl net.ipv6.conf.all.forwarding`（1 なら OK）
- ip6table_nat モジュールがロード：`sudo modprobe ip6table_nat`

#### 「WireGuard 停止 & iptables クリア」ボタン

WireGuard インターフェースを停止し、**ポータルが作成した** iptables・ip6tables ルール（DNAT / FORWARD / MASQUERADE）を削除します。使用をやめる場合や、iptables ルールが残留している場合に使用してください。

手動で追加したルールは削除されません（後述の「iptables ルールの識別」を参照）。

### 発行済みポート一覧

発行されたポートの一覧と削除ができます。各ポートの「設定を表示」ボタンで、IPv6 対応済みのクライアント設定を確認・ダウンロードできます。

- **方式**列のボタンで、トークンなしで HTTP ⇔ HTTPS を切り替えられます（鍵は変わりません）。
- 「トークン発行」ボタンで、そのポートの管理トークンを発行します（表示は一度だけ。以前のトークンは無効になります）。管理トークンのない旧版で発行したポートを、利用者が設定変更画面で操作できるようにするときに使います。

ポートを削除すると、自動適用が有効な場合は WireGuard 設定が即座に更新されます。管理者はモードにかかわらず常に削除できます。

### アクティビティログ

直近 200 行のログを管理画面から確認できます。ログファイルは `data/portal.log` に保存されます。

---

## ユーザー削除モード

一般ユーザーが自身の設定を削除できる方法を3つから選択できます。

| モード | 挙動 |
|--------|------|
| `none` | ポート番号を入力するだけで削除可（認証なし）|
| `token` | 設定生成時に発行される管理トークンが必要 |
| `admin` | 管理者のみ削除可。一般ユーザーの削除フォームを非表示にする |

デフォルトは `none` です。

#### `token` モードの動作

1. 「設定を生成する」を押すと、ページ上に**管理トークン**が一度だけ表示されます
2. ユーザーはトークンを安全な場所に保存しておきます
3. 削除時にポート番号とトークンの両方を入力することで削除できます
4. トークンをなくした場合は、管理者がポート一覧の「トークン再発行」で発行し直します（旧トークンは無効になります）

管理トークンは削除モードに関係なく毎回発行され、設定変更画面でも使います（次節）。

> **注意（`none` モードの場合）:** ポート番号を知っていれば誰でも削除できます。信頼できるユーザーのみがポータルにアクセスできる環境での利用を前提としています。

---

## 設定変更画面（`manage.php`）と管理トークン

発行済みのポートは、ポート番号と管理トークンを入力すると、利用者自身が次の操作を行えます。トップ画面の下部と、発行済みのポートを入力したときのエラーからリンクしています。

| 操作 | 内容 |
|------|------|
| 公開方式の変更 | HTTP ⇔ HTTPS。鍵は変わらないため、WireGuard の設定ファイルを入れ直す必要はない |
| 転送先ポートの変更 | PC 側の待ち受けポートを変える。鍵は変わらない |
| クライアント設定の再取得 | 発行時と同じ `.conf` を表示・ダウンロードする |
| 削除 | 削除モードが `admin` のときは表示しない |

- 管理トークンは削除モードに関係なく、発行時に毎回表示されます。DB にはハッシュだけを保存します（`wg_configs.delete_token` 列を使用）。
- 削除モードが `none` でも、設定変更画面の操作には管理トークンが必要です（`none` が意味するのは「トップ画面からトークンなしで削除できる」ことだけです）。
- 旧版で発行したポートには管理トークンがありません。管理者がポート一覧の「トークン発行」で発行して利用者に伝えてください。
- 公開方式・転送先ポートの変更ではピアが変わらないため、`wg-quick down/up` をせずに iptables のルールだけを入れ替えます（他のトンネルは切れません）。インターフェースが止まっているときは通常の適用になります。
- 自動適用が無効の場合、変更は保存だけされ、管理者が「全ポートを再適用」を押すまで反映されません。

---

## HTTPS 公開（nginx）

公開方式が HTTPS のポートは、DNAT の代わりに VPS の nginx が TLS を終端し、WireGuard トンネル経由で PC へ HTTP で転送します。PC 側のアプリは HTTP のままで構いません。

```
[外部ブラウザ] ── https://portal.example.com:11090 ──▶ [VPS の nginx (TLS 終端)]
                                                          │ http (WireGuard トンネル内)
                                                          ▼
                                                   [PC 10.0.0.2:80]
```

ポートごとに次のようなファイルが `nginx 設定の置き場所`（既定 `/etc/nginx/wgportal.d`）に作られます。HTTPS をやめたポートや削除したポートのファイルは消えます。

```nginx
# /etc/nginx/wgportal.d/https-11090.conf (自動生成)
server {
    listen 11090 ssl;
    listen [::]:11090 ssl;
    server_name portal.example.com;

    ssl_certificate     /etc/letsencrypt/live/portal.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/portal.example.com/privkey.pem;

    # 同じポートに http で来たら https へ移す
    error_page 497 =307 https://portal.example.com:11090$request_uri;

    client_max_body_size 1024m;

    location / {
        proxy_pass http://10.0.0.2:80;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        # Host は固定値にする (利用者が送った Host をそのまま渡さない)
        proxy_set_header Host portal.example.com:11090;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_buffering off;
        proxy_request_buffering off;
        proxy_read_timeout 360s;
    }
}
```

- PC 側のアプリには `Host: <ホスト名>:<ポート>` と `X-Forwarded-Proto: https` が届きます。アプリが `X-Forwarded-Proto` を見て URL を組み立てない場合、リダイレクト先やリンクが `http://` になることがあります。利用者向けの注意は管理画面の「HTTPS の注意書き」で表示できます。
- `proxy_buffering off` のため、アプリが返すストリーミング応答はそのまま流れます。

### 反映の手順と順序

nginx への反映は「ファイルを書き込む → `nginx -t` → 失敗したら書き込み前の内容に戻す → `systemctl reload nginx`」の順で行います。内容が変わらないときは何もしません（HTTPS を使わない環境には影響しません）。

WireGuard（iptables）側との順序は、切り替えで通信が途切れないように変えています。DNAT が残っている間は、外部からの通信を DNAT が nginx より先に取るためです。

| 変更 | 順序 |
|------|------|
| HTTP → HTTPS、HTTPS での新規発行 | nginx に追加 → DNAT を外す |
| HTTPS → HTTP | DNAT を戻す → nginx から外す |

どちらかが失敗したときは、DB の設定を元に戻し、逆の順序で反映し直します。

HTTP から HTTPS に切り替えるとき、そのポートで別のサービスが待ち受けていると拒否します（使用中ポートの自動ブロックが有効な場合）。

### 手動で書いた nginx 設定からの移行

すでに手動の nginx 設定で同じポートを HTTPS 公開している場合は、次の順で移行してください。

1. 手動の設定ファイル（`/etc/nginx/conf.d/` などに置いたもの）を削除またはリネームし、`sudo nginx -t && sudo systemctl reload nginx` を実行する
   - 手動の設定が同じポートで待ち受けたままだと、使用中ポートとして切り替えが拒否されます
2. 管理画面のポート一覧（または設定変更画面）で、そのポートを HTTPS に切り替える
3. `ls /etc/nginx/wgportal.d/` に `https-<ポート>.conf` ができていることと、`https://<ホスト名>:<ポート>/` に接続できることを確認する

---

## iptables ルールの識別（`--comment wgportal`）

ポータルが生成する iptables / ip6tables ルールには、すべて `-m comment --comment wgportal` が付与されます。

```
-A PREROUTING -i ens3 -p tcp -m tcp --dport 11090 -m comment --comment wgportal -j DNAT --to-destination 10.100.10.2:80
-A FORWARD -d 10.100.10.2/32 -p tcp -m tcp --dport 80 -m comment --comment wgportal -j ACCEPT
-A POSTROUTING -o wg0 -m comment --comment wgportal -j MASQUERADE
```

残留ルールの掃除（設定生成・削除・「設定適用」「停止」ボタンのたびに実行される）は、**このコメントを持つルールだけ**を対象にします。したがって、同じサブネット宛であっても管理者が手動で追加したルールは削除されません。目印は設定 `rule_comment` で変えられます（「同じサーバーで検証用のポータルを動かす」を参照）。

```bash
# ポータルが管理しているルールだけを一覧する
sudo iptables-save | grep wgportal
sudo ip6tables-save | grep wgportal
```

> `comment` match は `xt_comment` カーネルモジュールが提供し、iptables と ip6tables で共通です。IPv6 側でも同じ書式で使用できます。

### v1.2.0 以前からの移行

v1.2.0 以前は「ルールにサブネット文字列が含まれるか」だけで削除対象を判定していたため、同じサブネット宛の手動ルールまで削除されていました。

更新後の**初回のポータル操作時に一度だけ**、旧形式（コメント無し）のルールを自動で掃除します。対象は旧バージョンが実際に出力していた形状に完全一致するルールのみ（転送先ポートが `80` 固定の DNAT / FORWARD と、WireGuard インターフェースの MASQUERADE）で、手動ルールは形状が一致しないため残ります。掃除の実行結果はアクティビティログに記録され、以後は再実行されません。

移行手順:

1. `git pull` でファイルを更新する
2. 管理画面の「全ポートを再適用」ボタンを押す（または設定を1件生成する）
   - 旧ルールが削除され、コメント付きの新ルールが投入されます
3. 手動ルールが残っていることを確認する

```bash
sudo iptables-save | grep -v wgportal   # 手動ルールが残っているか確認
sudo iptables-save | grep wgportal      # ポータル製ルールにコメントが付いているか確認
```

`/etc/wireguard/wg0.conf` の `PostUp` / `PostDown` も同じコメント付きの行に書き換わり、`-A` と `-D` で対になります。

---

## 既存 wg0 と共存する場合

`wg0` がすでに使用中の場合は、管理画面で以下を変更してください。

1. **WireGuard インターフェース名** → `wg1` や `wg10` など
2. **WireGuard ポート (UDP)** → `51821` など未使用のポート
3. ファイアウォールを開放: `sudo ufw allow [新しいポート]/udp`

---

## 同じサーバーで検証用のポータルを動かす

更新を本番に入れる前に、同じサーバーの別ディレクトリで実際の転送まで試せます。次の値を本番と分ければ、検証用ポータルの操作（設定適用・停止を含む）は本番のポートに影響しません。

| 項目 | 設定のキー | 例 | 分ける理由 |
|------|-----------|-----|-----------|
| iptables ルールの目印 | `rule_comment` | `wgportal-test` | 掃除の対象は自分の目印のルールだけ |
| WireGuard インターフェース名 | `wg_interface` | `wgtest` | 設定ファイルと `wg-quick down/up` の対象 |
| WireGuard ポート (UDP) | `wg_port` | `51821` | 待ち受けの衝突 |
| サブネット (IPv4 / IPv6) | `subnet` / `subnet_ipv6` | `10.0.99` / `fd00:99::` | 転送先アドレスの衝突 |
| nginx 設定の置き場所 | `nginx_conf_dir` | `/etc/nginx/wgportal-test.d` | HTTPS 用ファイルの管理範囲 |
| 許可ポート | `allowed_ports` | `19000-19009` | 本番で使っている公開ポートとの衝突 |

`rule_comment` は管理画面にない設定です。ブラウザで開く前に、コマンドラインから他の値と一緒に設定してください（未設定の値は管理画面で変えられます）。

```bash
git clone -b <試すブランチ> https://github.com/bee7813993/wireguard-portal.git /path/to/wireguard-portal-test
cd /path/to/wireguard-portal-test
read -rsp '管理パスワード: ' PW; echo
PW="$PW" php -r '
require "config.php";
set_setting("admin_pass", password_hash(getenv("PW"), PASSWORD_DEFAULT));
foreach ([
    "rule_comment"   => "wgportal-test",
    "wg_interface"   => "wgtest",
    "wg_port"        => "51821",
    "subnet"         => "10.0.99",
    "subnet_ipv6"    => "fd00:99::",
    "nginx_conf_dir" => "/etc/nginx/wgportal-test.d",
    "allowed_ports"  => "19000-19009",
    "auto_apply"     => "0",
] as $k => $v) set_setting($k, $v);
echo "ok\n";'
unset PW
```

そのあと:

1. `data/` が外から読めないことを確認する（`curl -I https://<ホスト>/<検証用のパス>/data/wg_portal.sqlite` が 403 か 404）
2. 管理画面で VPS の IP・NIC・HTTPS のホスト名などを本番と同じ値にし、準備ができたら自動適用を有効にする
3. `sudo ufw allow 51821/udp`、HTTPS を試すなら許可ポートの TCP も開ける
4. HTTPS を試す場合は「8. HTTPS 公開の準備」と同じ手順で検証用のディレクトリを作り、`include` 行を足す

本番の許可ポートに検証用の範囲を含めないでください。両方が同じ公開ポートに DNAT を入れると、どちらが効くかが反映の順番で変わります。

片付けるときは、検証用の管理画面で「WireGuard 停止 & iptables クリア」を押してからディレクトリを消し、`/etc/wireguard/wgtest.conf`、検証用の nginx ディレクトリと `include` 行を削除して nginx を再読み込みします。

---

## セキュリティ注意事項

- 管理画面のパスワードは初期値 `changeme` から**必ず変更**してください。
- `data/` ディレクトリへの外部アクセスは nginx 設定で遮断してください（上記設定に含まれています）。
- 本ポータルはポート番号を知っていれば誰でも設定を生成できます。信頼できるネットワーク内、または認証付きのリバースプロキシ配下での運用を推奨します。
- ユーザー削除モードが `none` の場合、ポート番号を知っていれば誰でも他人の設定も削除できます。不特定多数がアクセスできる環境では `token` または `admin` モードを選択してください。
- 発行済みのポートはトップ画面から作り直せません（他人に鍵と管理トークンを作り直されないようにするため）。作り直す場合は管理者が削除してから発行し直してください。
- HTTPS 公開では www-data が nginx の設定ディレクトリに書き込めます。www-data は以前から WireGuard 設定の `PostUp` で root 権限のコマンドを書ける（sudoers の `tee /etc/wireguard/*` と `wg-quick up`）ため、権限の範囲は広がりませんが、ポータルのファイルと `data/` の保護は引き続き重要です。nginx 設定に埋め込む値（ホスト名・証明書のパス）は使える文字を絞って検査しています。
- 本番環境では HTTPS を必ず使用してください（秘密鍵がネットワークを流れます）。
- 使用中ポートの自動ブロックが有効な場合、生成時のエラーメッセージから「そのポートでサービスが動作している」ことが利用者に分かります（外部からのポートスキャンでも判別できる情報です）。ループバック専用の内部サービスはチェック対象外のため、エラーから存在が漏れることはありません。ポートの存在自体を利用者に知らせたくない場合は、許可ポートリストで利用範囲を絞ることを推奨します。

---

## エンドユーザー向けガイド

利用者への案内は `docs/利用ガイド.md` を GitHub Wiki にコピーして使用してください。

---

## 更新履歴

### v1.4.0 — HTTPS 公開 / 設定変更画面

**新機能**
- **公開方式の選択**: ポートごとに HTTP（DNAT）と HTTPS（nginx で TLS を終端）を選べるように（`wg_configs.proxy_mode` を追加）。新規発行時の既定値は管理画面で設定できる
- **設定変更画面（`manage.php`）**: ポート番号と管理トークンで、公開方式・転送先ポートの変更、クライアント設定の再取得、削除ができる。鍵は作り直さない
- **管理トークン**: 削除モードに関係なく発行時に毎回表示する（旧「削除トークン」と同じもの）。管理画面のポート一覧から発行・再発行できる
- 管理画面のポート一覧から、トークンなしで公開方式を切り替えられる
- 公開方式・転送先ポートの変更は iptables のルールだけを入れ替え、`wg-quick down/up` をしない（他のトンネルが切れない）

**変更**
- 発行済みのポートをトップ画面から作り直せないようにした（設定変更画面を案内する）
- 「IPv6 対応で全ポートを再適用」ボタンを「全ポートを再適用」に改称し、nginx 設定も反映するようにした
- 利用者向け画面のスタイルを `portal.css` に分けた
- iptables ルールの目印を設定 `rule_comment` で変えられるようにした（同じサーバーで検証用のポータルを動かすため）
- 管理ログインを設置ディレクトリごとに分けた（同じドメインの別のポータルとログイン状態を共有しない）。更新後に一度ログインし直しが必要

**備考**
- 既存のポートはすべて HTTP（DNAT）のまま移行される。HTTPS を使わない場合、sudoers と nginx の変更は不要
- HTTPS を使う場合は、インストール手順「8. HTTPS 公開の準備」を行う

---

### v1.3.0 (2026-08-05) — 手動 iptables ルールの保護 / 転送先ポートの設定

**修正**
- **手動 iptables ルールが消える問題を修正**: 残留ルールの掃除がサブネット文字列一致で判定していたため、同じサブネット宛の手動ルールまで削除されていた。ポータル製ルールに `-m comment --comment wgportal` を付与し、掃除はこのコメントを持つルールだけを対象にするよう変更（IPv4 / IPv6 の PREROUTING / FORWARD / POSTROUTING すべて）
- 旧形式（コメント無し）のルールは、更新後の初回操作時に一度だけ自動で掃除される（対象は旧バージョンが出力していた形状に完全一致するルールのみ）
- 削除コマンドを組み立てる際、`iptables -S` の出力をシェルへ素通しせずトークン単位でエスケープするよう変更

**新機能**
- **転送先ポートの設定**: DNAT の宛先ポートが `80` 固定だったのを設定可能に（`wg_configs.dest_port` を追加）。トップ画面のフォームでポートごとに指定でき、既定値は管理画面から変更できる

**備考**
- sudoers の変更は不要（`-D` / `-A` 行のワイルドカードが追加引数を許容する）
- 既存レコードの `dest_port` は `80` として移行される

---

### v1.2.0 (2026-07-24) — ポート制限

**新機能**
- **使用中ポートの自動ブロック**: サーバー上で LISTEN 中の TCP ポート（SSH・Web サーバー等）への設定生成を拒否。既存サービスのポートを DNAT が乗っ取り、アクセス不能になる事故を防止（デフォルト有効）。ループバック専用のサービスは対象外
- **禁止ポート / 許可ポートリスト**: 管理画面からカンマ区切り・範囲指定（例: `2222, 8000-8100`）で設定生成可能なポートを制限可能
- **予約ポートの保護**: WireGuard ポート（`wg_port`）とポータル自身のポートへの設定生成を拒否
- 管理画面に現在 LISTEN 中の TCP ポート一覧を参考表示

**備考**
- ポート制限は**新規ポートの発行時のみ**適用。発行済みポートの再発行（設定更新・トークン再発行）と削除は制限されない
- `ss` / `netstat` / PHP の `exec` が使えない環境では使用中ポートチェックはスキップされる（ログに WARN を記録）
- 管理画面の設定フォームに保存マーカーを追加し、フォーム以外の POST（ログインフォームの再送信等）が設定を書き換えないよう修正

---

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
