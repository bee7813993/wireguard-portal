<?php
// =========================================================
//  wg_manager.php  – 設定ファイル生成 & システム適用
// =========================================================
require_once __DIR__ . '/config.php';

class WgManager {

    // ポータルが生成した iptables/ip6tables ルールに付ける目印。
    // 削除対象の判定はこのコメントの有無だけで行い、
    // 管理者が手動で追加したルールを巻き込まないようにする。
    // (空白を含まない語にすること: iptables -S の出力が引用符無しになり
    //  PostUp/PostDown 行との対応が崩れない)
    private const RULE_COMMENT = 'wgportal';

    // ---- ルールに付与する comment match ------------------
    private static function comment_opt(): string {
        return '-m comment --comment ' . self::RULE_COMMENT;
    }

    // ---- インターフェース名バリデーション ---------------
    private static function sanitize_interface(string $iface): string {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,14}$/', $iface)) {
            throw new RuntimeException('不正なインターフェース名です。');
        }
        return $iface;
    }

    // ---- Windows クライアント用 .conf 生成 ---------------
    public static function build_client_conf(
        string $client_priv,
        string $server_pub,
        string $client_ip,
        string $server_ip
    ): string {
        $vps_ip  = get_setting('vps_ip');
        $wg_port = get_setting('wg_port');
        $subnet_ipv6 = get_setting('subnet_ipv6');

        // IPv6 アドレス生成
        $ipv4_parts = explode('.', $client_ip);
        $ipv4_last = (int)end($ipv4_parts);
        $client_ip6 = $subnet_ipv6 . dechex($ipv4_last);
        $server_ip6 = $subnet_ipv6 . '1';

        return "[Interface]\n"
             . "PrivateKey = {$client_priv}\n"
             . "Address = {$client_ip}/24, {$client_ip6}/64\n"
             . "DNS = 1.1.1.1\n"
             . "\n"
             . "[Peer]\n"
             . "PublicKey = {$server_pub}\n"
             . "Endpoint = {$vps_ip}:{$wg_port}\n"
             . "AllowedIPs = {$server_ip}/32, {$server_ip6}/128\n"
             . "PersistentKeepalive = 25\n";
    }

    // ---- 全ピアを含む完全サーバー設定ファイル生成 --------
    public static function build_full_server_conf(): string {
        $server_kp = get_or_create_server_keypair();
        $subnet    = get_setting('subnet');
        $subnet_ipv6 = get_setting('subnet_ipv6');
        $server_ip = $subnet . '.1';
        $server_ip6 = $subnet_ipv6 . '1';
        $wg_port   = get_setting('wg_port');
        $nic       = get_setting('nic');
        $iface     = self::sanitize_interface(get_setting('wg_interface') ?: 'wg0');

        $db   = get_db();
        $rows = $db->query("SELECT id, port, client_pub, dest_port FROM wg_configs ORDER BY id ASC")->fetchAll();

        // ポータル製ルールの目印 (PostUp / PostDown で必ず同じ文字列を使う)
        $cmt = self::comment_opt();

        // MASQUERADE は一度だけ (IPv4 & IPv6)
        $up_lines   = [
            "iptables -t nat -A POSTROUTING -o {$iface} {$cmt} -j MASQUERADE",
            "ip6tables -t nat -A POSTROUTING -o {$iface} {$cmt} -j MASQUERADE",
        ];
        $down_lines = [
            "iptables -t nat -D POSTROUTING -o {$iface} {$cmt} -j MASQUERADE",
            "ip6tables -t nat -D POSTROUTING -o {$iface} {$cmt} -j MASQUERADE",
        ];

        $peer_blocks = '';
        foreach ($rows as $row) {
            $octet      = (($row['id'] - 1) % 253) + 2;
            $client_ip  = $subnet . '.' . $octet;
            $client_ip6 = $subnet_ipv6 . dechex($octet);
            $port       = (int)$row['port'];
            $dest       = (int)($row['dest_port'] ?? 0) ?: 80;

            // IPv4 ルール
            $up_lines[]   = "iptables -t nat -A PREROUTING -i {$nic} -p tcp --dport {$port} {$cmt} -j DNAT --to-destination {$client_ip}:{$dest}";
            $up_lines[]   = "iptables -A FORWARD -p tcp -d {$client_ip} --dport {$dest} {$cmt} -j ACCEPT";
            $down_lines[] = "iptables -t nat -D PREROUTING -i {$nic} -p tcp --dport {$port} {$cmt} -j DNAT --to-destination {$client_ip}:{$dest}";
            $down_lines[] = "iptables -D FORWARD -p tcp -d {$client_ip} --dport {$dest} {$cmt} -j ACCEPT";

            // IPv6 ルール
            $up_lines[]   = "ip6tables -t nat -A PREROUTING -i {$nic} -p tcp --dport {$port} {$cmt} -j DNAT --to-destination [{$client_ip6}]:{$dest}";
            $up_lines[]   = "ip6tables -A FORWARD -p tcp -d {$client_ip6} --dport {$dest} {$cmt} -j ACCEPT";
            $down_lines[] = "ip6tables -t nat -D PREROUTING -i {$nic} -p tcp --dport {$port} {$cmt} -j DNAT --to-destination [{$client_ip6}]:{$dest}";
            $down_lines[] = "ip6tables -D FORWARD -p tcp -d {$client_ip6} --dport {$dest} {$cmt} -j ACCEPT";

            // WireGuardは両方のアドレスを許可
            $peer_blocks .= "\n[Peer]\n"
                         .  "PublicKey = {$row['client_pub']}\n"
                         .  "AllowedIPs = {$client_ip}/32, {$client_ip6}/128\n";
        }

        $conf = "[Interface]\n"
              . "Address = {$server_ip}/24, {$server_ip6}/64\n"
              . "ListenPort = {$wg_port}\n"
              . "PrivateKey = {$server_kp['priv']}\n"
              . "\n";

        foreach ($up_lines   as $rule) $conf .= "PostUp   = {$rule}\n";
        foreach ($down_lines as $rule) $conf .= "PostDown = {$rule}\n";

        return $conf . $peer_blocks;
    }

    // ---- VPS サーバー用 wg.conf 生成 (単一ポート・後方互換) ---
    public static function build_server_conf_snippet(
        string $server_priv,
        string $client_pub,
        string $server_ip,
        string $client_ip,
        int    $ext_port,
        int    $dest_port = 80
    ): string {
        $wg_port = get_setting('wg_port');
        $nic     = get_setting('nic');
        $iface   = get_setting('wg_interface') ?: 'wg0';
        $subnet_ipv6 = get_setting('subnet_ipv6');

        // IPv6 アドレス生成 (IPv4 の最後の八進数から十六進数に変換)
        $ipv4_parts = explode('.', $client_ip);
        $ipv4_last = (int)end($ipv4_parts);
        $client_ip6 = $subnet_ipv6 . dechex($ipv4_last);
        $server_ip6 = $subnet_ipv6 . '1';

        $post_up   = self::iptables_rules($nic, $iface, $ext_port, $client_ip, '-A', '-A', $dest_port);
        $post_up6  = self::ip6tables_rules($nic, $iface, $ext_port, $client_ip6, '-A', '-A', $dest_port);
        $post_down = self::iptables_rules($nic, $iface, $ext_port, $client_ip, '-D', '-D', $dest_port);
        $post_down6 = self::ip6tables_rules($nic, $iface, $ext_port, $client_ip6, '-D', '-D', $dest_port);

        return "[Interface]\n"
             . "Address = {$server_ip}/24, {$server_ip6}/64\n"
             . "ListenPort = {$wg_port}\n"
             . "PrivateKey = {$server_priv}\n"
             . "\n"
             . "PostUp   = {$post_up[0]}\n"
             . "PostUp   = {$post_up[1]}\n"
             . "PostUp   = {$post_up[2]}\n"
             . "PostUp   = {$post_up6[0]}\n"
             . "PostUp   = {$post_up6[1]}\n"
             . "PostUp   = {$post_up6[2]}\n"
             . "PostDown = {$post_down[0]}\n"
             . "PostDown = {$post_down[1]}\n"
             . "PostDown = {$post_down[2]}\n"
             . "PostDown = {$post_down6[0]}\n"
             . "PostDown = {$post_down6[1]}\n"
             . "PostDown = {$post_down6[2]}\n"
             . "\n"
             . "[Peer]\n"
             . "PublicKey = {$client_pub}\n"
             . "AllowedIPs = {$client_ip}/32, {$client_ip6}/128\n";
    }

    private static function iptables_rules(
        string $nic,
        string $iface,
        int    $ext_port,
        string $client_ip,
        string $nat_flag,
        string $fwd_flag,
        int    $dest_port = 80
    ): array {
        $cmt = self::comment_opt();
        return [
            "iptables -t nat {$nat_flag} PREROUTING -i {$nic} -p tcp --dport {$ext_port} {$cmt} -j DNAT --to-destination {$client_ip}:{$dest_port}",
            "iptables {$fwd_flag} FORWARD -p tcp -d {$client_ip} --dport {$dest_port} {$cmt} -j ACCEPT",
            "iptables -t nat {$nat_flag} POSTROUTING -o {$iface} {$cmt} -j MASQUERADE",
        ];
    }

    private static function ip6tables_rules(
        string $nic,
        string $iface,
        int    $ext_port,
        string $client_ip6,
        string $nat_flag,
        string $fwd_flag,
        int    $dest_port = 80
    ): array {
        $cmt = self::comment_opt();
        return [
            "ip6tables -t nat {$nat_flag} PREROUTING -i {$nic} -p tcp --dport {$ext_port} {$cmt} -j DNAT --to-destination [{$client_ip6}]:{$dest_port}",
            "ip6tables {$fwd_flag} FORWARD -p tcp -d {$client_ip6} --dport {$dest_port} {$cmt} -j ACCEPT",
            "ip6tables -t nat {$nat_flag} POSTROUTING -o {$iface} {$cmt} -j MASQUERADE",
        ];
    }

    // ---- セットアップコマンド生成 -------------------------
    public static function build_setup_commands(int $ext_port): string {
        $wg_port = get_setting('wg_port');
        $iface   = get_setting('wg_interface') ?: 'wg0';

        return "# 1. WireGuardインストール\n"
             . "sudo apt update && sudo apt install wireguard iptables -y\n\n"
             . "# 2. IP フォワーディングを有効化 (IPv4 & IPv6)\n"
             . "echo \"net.ipv4.ip_forward=1\" | sudo tee -a /etc/sysctl.conf\n"
             . "echo \"net.ipv6.conf.all.forwarding=1\" | sudo tee -a /etc/sysctl.conf\n"
             . "sudo sysctl -p\n\n"
             . "# 3. ip6tables NAT モジュールを有効化 (IPv6 NAT に必要)\n"
             . "sudo modprobe ip6table_nat\n"
             . "echo 'ip6table_nat' | sudo tee -a /etc/modules\n\n"
             . "# 4. {$iface}.conf を配置 (上の内容を保存)\n"
             . "sudo nano /etc/wireguard/{$iface}.conf\n\n"
             . "# 5. 起動・自動起動\n"
             . "sudo wg-quick up {$iface}\n"
             . "sudo systemctl enable wg-quick@{$iface}\n\n"
             . "# 6. ファイアウォール開放\n"
             . "sudo ufw allow {$wg_port}/udp\n"
             . "sudo ufw allow {$ext_port}/tcp\n\n"
             . "# 7. 状態確認\n"
             . "sudo wg show\n"
             . "sudo ip6tables -t nat -L -n\n";
    }

    // ---- WireGuard停止 & iptables全削除 -----------------
    public static function teardown(): array {
        $iface = self::sanitize_interface(get_setting('wg_interface') ?: 'wg0');

        exec('sudo wg show ' . escapeshellarg($iface) . ' 2>/dev/null', $_out, $running);

        $out1 = [];
        if ($running === 0) {
            // wg-quick down がPostDownを実行してiptablesを削除する
            exec('sudo wg-quick down ' . escapeshellarg($iface) . ' 2>&1', $out1, $s1);
        }

        // wg-quick downで消えなかった残留ルールを確実に削除
        self::flush_stale_iptables($iface);

        $output = trim(implode("\n", $out1));
        write_log('INFO', "WireGuard停止 & iptablesクリア完了: {$iface}");

        return ['success' => true, 'output' => $output ?: "iptablesルールをクリアしました。"];
    }

    // ---- 残留iptablesルールの削除 ------------------------
    // 削除対象はポータルが付けた --comment wgportal を持つルールのみ。
    // サブネット文字列での一致判定 (v1.x) は、同じサブネット宛の
    // 手動ルールまで巻き込んで削除してしまうため使用しない。
    private static function flush_stale_iptables(string $iface): void {
        foreach (self::rule_chains() as [$cmd, $table_opt, $chain]) {
            foreach (self::list_rules($cmd, $table_opt, $chain) as $rule) {
                if (!self::is_portal_rule($rule)) continue;
                self::delete_rule($cmd, $table_opt, $rule);
            }
        }

        // コメント付与前に作られた旧ルールを一度だけ掃除する
        self::flush_legacy_iptables($iface);
    }

    // ---- 走査対象チェーン一覧 [コマンド, テーブル指定, チェーン] --
    private static function rule_chains(): array {
        return [
            ['iptables',  '-t nat ', 'PREROUTING'],
            ['iptables',  '',        'FORWARD'],
            ['iptables',  '-t nat ', 'POSTROUTING'],
            // ip6tables も xt_comment (comment match) を共有するため
            // IPv4 と同じ書式で -m comment --comment が使える
            ['ip6tables', '-t nat ', 'PREROUTING'],
            ['ip6tables', '',        'FORWARD'],
            ['ip6tables', '-t nat ', 'POSTROUTING'],
        ];
    }

    private static function list_rules(string $cmd, string $table_opt, string $chain): array {
        $out = [];
        exec("sudo {$cmd} {$table_opt}-S {$chain} 2>/dev/null", $out);
        return $out;
    }

    // ---- ポータルが作成したルールか判定 -------------------
    private static function is_portal_rule(string $rule): bool {
        // iptables のバージョンによりコメントが引用符付きで出力される場合がある
        return (bool)preg_match(
            '/--comment\s+"?' . preg_quote(self::RULE_COMMENT, '/') . '"?(\s|$)/',
            $rule
        );
    }

    // ---- -S の出力行をそのまま -D で削除 ------------------
    private static function delete_rule(string $cmd, string $table_opt, string $rule): void {
        $del = preg_replace('/^-A /', '-D ', $rule, 1);

        // 出力行をシェルへ素通しせず、トークン単位でエスケープする。
        // 対象ルールはポータル製に限られ、値に空白は含まれないため
        // 空白区切りで安全に分解できる。
        $argv = [];
        foreach (preg_split('/\s+/', trim($del)) as $token) {
            // 引用符付きで出力されたコメントを元に戻す
            if (preg_match('/^"(.*)"$/', $token, $m)) $token = $m[1];
            $argv[] = escapeshellarg($token);
        }
        exec("sudo {$cmd} {$table_opt}" . implode(' ', $argv) . ' 2>/dev/null');
    }

    // ---- 旧形式 (コメント無し) ルールの一度きりの掃除 ------
    // v1.x が生成したルールにはコメントが無いため、
    // ポータルが実際に出力していた形状に完全一致するものだけを削除する
    // (転送先ポートは常に 80 固定だった)。管理者の手動ルールは
    // 形状が一致しないため残る。
    private static function flush_legacy_iptables(string $iface): void {
        if (get_setting('iptables_comment_migrated') === '1') return;

        $q           = fn(string $s): string => preg_quote($s, '/');
        $nic         = get_setting('nic');
        $subnet      = get_setting('subnet');
        $subnet_ipv6 = get_setting('subnet_ipv6');
        $masq        = '/^-A POSTROUTING -o ' . $q($iface) . ' -j MASQUERADE$/';

        $legacy = [
            ['iptables',  '-t nat ', 'PREROUTING',
             '/^-A PREROUTING -i ' . $q($nic) . ' -p tcp (?:-m tcp )?--dport \d{1,5}'
             . ' -j DNAT --to-destination ' . $q($subnet) . '\.\d{1,3}:80$/'],
            ['iptables',  '',        'FORWARD',
             '/^-A FORWARD -d ' . $q($subnet) . '\.\d{1,3}\/32 -p tcp (?:-m tcp )?--dport 80 -j ACCEPT$/'],
            ['iptables',  '-t nat ', 'POSTROUTING', $masq],
            ['ip6tables', '-t nat ', 'PREROUTING',
             '/^-A PREROUTING -i ' . $q($nic) . ' -p tcp (?:-m tcp )?--dport \d{1,5}'
             . ' -j DNAT --to-destination \[' . $q($subnet_ipv6) . '[0-9a-fA-F]{1,4}\]:80$/'],
            ['ip6tables', '',        'FORWARD',
             '/^-A FORWARD -d ' . $q($subnet_ipv6) . '[0-9a-fA-F]{1,4}\/128 -p tcp (?:-m tcp )?--dport 80 -j ACCEPT$/'],
            ['ip6tables', '-t nat ', 'POSTROUTING', $masq],
        ];

        $listed  = false;
        $removed = 0;
        foreach ($legacy as [$cmd, $table_opt, $chain, $pattern]) {
            $rules = self::list_rules($cmd, $table_opt, $chain);
            // -S は最低でもポリシー行 (-P ...) を返す。空なら参照失敗
            if ($rules) $listed = true;
            foreach ($rules as $rule) {
                if (!preg_match($pattern, $rule)) continue;
                self::delete_rule($cmd, $table_opt, $rule);
                $removed++;
            }
        }

        // iptables を参照できない環境では完了扱いにせず次回に持ち越す
        if (!$listed) return;

        set_setting('iptables_comment_migrated', '1');
        write_log('INFO', "旧形式 (コメント無し) の iptables ルールを {$removed} 件削除しました");
    }

    // ---- サーバーへ設定を適用 ----------------------------
    public static function apply_server_config(): array {
        $iface     = self::sanitize_interface(get_setting('wg_interface') ?: 'wg0');
        $conf      = self::build_full_server_conf();
        $conf_path = "/etc/wireguard/{$iface}.conf";

        // sudo tee で root 権限が必要なパスへ書き込み
        $proc = proc_open(
            'sudo tee ' . escapeshellarg($conf_path) . ' > /dev/null',
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => "設定ファイルの書き込みに失敗しました: {$conf_path}"];
        }
        fwrite($pipes[0], $conf);
        fclose($pipes[0]);
        $err      = stream_get_contents($pipes[2]);
        $writeRet = proc_close($proc);
        if ($writeRet !== 0) {
            return ['success' => false, 'output' => "書き込みエラー: " . trim($err)];
        }
        exec('sudo chmod 600 ' . escapeshellarg($conf_path));

        // インターフェースが起動中か確認
        exec('sudo wg show ' . escapeshellarg($iface) . ' 2>/dev/null', $_out, $running);

        if ($running === 0) {
            exec('sudo wg-quick down ' . escapeshellarg($iface) . ' 2>&1', $out1, $s1);
        } else {
            $out1 = [];
        }

        // wg-quick down では消えない古い残留iptablesルールを全削除
        self::flush_stale_iptables($iface);

        exec('sudo wg-quick up ' . escapeshellarg($iface) . ' 2>&1', $out2, $s2);
        $output  = implode("\n", array_merge($out1, $out2));
        $success = ($s2 === 0);

        if ($success) {
            write_log('INFO', "設定適用完了: {$iface}");
        } else {
            write_log('ERROR', "設定適用失敗: {$iface} - " . trim($output));
        }

        return ['success' => $success, 'output' => trim($output)];
    }

    // ---- 既存ポートの設定を鍵を変えずに再生成 ------------
    public static function rebuild_client_conf(int $port): ?array {
        $db   = get_db();
        $stmt = $db->prepare("SELECT id, client_priv, client_pub, dest_port FROM wg_configs WHERE port = ?");
        $stmt->execute([$port]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $server_kp   = get_or_create_server_keypair();
        $subnet      = get_setting('subnet');
        $server_ip   = $subnet . '.1';
        $octet       = (((int)$row['id'] - 1) % 253) + 2;
        $client_ip   = $subnet . '.' . $octet;

        $client_conf = self::build_client_conf(
            $row['client_priv'], $server_kp['pub'], $client_ip, $server_ip
        );
        return [
            'port'        => $port,
            'dest_port'   => (int)($row['dest_port'] ?? 0) ?: 80,
            'client_ip'   => $client_ip,
            'client_conf' => $client_conf,
        ];
    }

    // ---- メイン: ポートに対してキーペアを発行/再発行 ----
    // $dest_port はトンネル内 PC 側の転送先ポート (DNAT の宛先)
    public static function issue(int $port, int $dest_port = 80): array {
        $db          = get_db();
        $server_kp   = get_or_create_server_keypair();
        $client_kp   = wg_generate_keypair();
        $subnet      = get_setting('subnet');
        $server_ip   = $subnet . '.1';
        $delete_mode = get_setting('delete_mode') ?: 'none';

        // delete_mode=token のとき削除トークンを生成
        $plaintext_token = null;
        $hashed_token    = null;
        if ($delete_mode === 'token') {
            $plaintext_token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            $hashed_token    = password_hash($plaintext_token, PASSWORD_DEFAULT);
        }

        // UPSERT (server_priv/pub はグローバル鍵を記録)
        $db->prepare("
            INSERT INTO wg_configs (port, dest_port, client_priv, client_pub, server_priv, server_pub, delete_token, updated_at)
            VALUES (:port, :dport, :cp, :cP, :sp, :sP, :dt, CURRENT_TIMESTAMP)
            ON CONFLICT(port) DO UPDATE SET
                dest_port    = excluded.dest_port,
                client_priv  = excluded.client_priv,
                client_pub   = excluded.client_pub,
                server_priv  = excluded.server_priv,
                server_pub   = excluded.server_pub,
                delete_token = excluded.delete_token,
                updated_at   = CURRENT_TIMESTAMP
        ")->execute([
            ':port'  => $port,
            ':dport' => $dest_port,
            ':cp'    => $client_kp['priv'],
            ':cP'    => $client_kp['pub'],
            ':sp'    => $server_kp['priv'],
            ':sP'    => $server_kp['pub'],
            ':dt'    => $hashed_token,
        ]);

        // UPSERT後に実際のidを取得してIPを算出（削除後の飛び番対策）
        $stmt = $db->prepare("SELECT id FROM wg_configs WHERE port = ?");
        $stmt->execute([$port]);
        $actual_id = (int)$stmt->fetchColumn();
        $octet     = (($actual_id - 1) % 253) + 2;
        $client_ip = $subnet . '.' . $octet;

        $client_conf = self::build_client_conf(
            $client_kp['priv'], $server_kp['pub'], $client_ip, $server_ip
        );
        // UPSERT 後に呼ぶことで今回のピアを含む完全設定を返す
        $server_conf = self::build_full_server_conf();
        $setup_cmds  = self::build_setup_commands($port);

        write_log('INFO', "ポート {$port} の設定を生成しました (client_ip={$client_ip}, dest_port={$dest_port})");

        $applied = null;
        if (get_setting('auto_apply') === '1') {
            $applied = self::apply_server_config();
            if ($applied['success']) {
                write_log('INFO', "ポート {$port} 自動適用完了");
            } else {
                write_log('ERROR', "ポート {$port} 自動適用失敗: " . $applied['output']);
            }
        }

        return [
            'port'         => $port,
            'dest_port'    => $dest_port,
            'client_ip'    => $client_ip,
            'client_conf'  => $client_conf,
            'server_conf'  => $server_conf,
            'setup_cmds'   => $setup_cmds,
            'applied'      => $applied,
            'delete_token' => $plaintext_token,
        ];
    }
}
