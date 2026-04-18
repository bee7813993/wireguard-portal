# WireGuard Portal

ローカル Web サーバーを VPS 経由で公開するための WireGuard 設定発行ポータルです。

## ファイル構成

```
wireguard_portal/
├── index.php       # 公開ページ（ポート入力 → 設定生成）
├── admin.php       # 管理画面（内部パラメーター設定）
├── generate.php    # Ajax API エンドポイント
├── wg_manager.php  # WireGuard 設定生成クラス
├── config.php      # DB 接続・共通関数
└── data/           # SQLite DB が自動作成される（.htaccess で保護）
```

## 必要要件

- PHP 8.0 以上
- php-sqlite3 拡張
- php-sodium 拡張（推奨）または VPS に `wg` コマンドがインストール済み

## インストール

```bash
# 1. ファイルを Web ルートに配置
cp -r wireguard_portal/ /var/www/html/wg-portal/

# 2. data/ ディレクトリの権限設定
chmod 750 /var/www/html/wg-portal/data/
chown www-data:www-data /var/www/html/wg-portal/data/

# 3. data/ ディレクトリを Web から直接アクセスできないよう保護
cat > /var/www/html/wg-portal/data/.htaccess << 'EOF'
Deny from all
EOF
```

## 初期設定

1. ブラウザで `https://your-domain/wg-portal/admin.php` を開く
2. デフォルトパスワード `changeme` でログイン
3. VPS IP・WireGuard ポート・NIC 名・サブネットを設定
4. **必ずパスワードを変更する**

## 使い方（エンドユーザー）

1. `https://your-domain/wg-portal/` を開く
2. 外部公開したいポート番号を入力して「設定を生成する」
3. `wg-client.conf` をダウンロードして WireGuard for Windows でインポート
4. VPS に SSH して表示された `wg0.conf` と「セットアップコマンド」を実行
5. Windows 側でトンネルを有効化

## セキュリティ注意事項

- `data/` ディレクトリは必ず `.htaccess` で保護すること
- 管理画面は IP 制限または Basic 認証を追加することを推奨
- 本番環境では HTTPS 必須（秘密鍵がネットワークに流れる）
- 同じポートを再入力すると鍵が再発行される（旧設定は無効化）
