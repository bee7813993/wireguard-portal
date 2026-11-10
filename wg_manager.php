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
    // 同じサーバーで検証用のポータルを動かすときは、設定 rule_comment で
    // 別の目印にする。目印が違えば互いのルールを消さない。
    private const RULE_COMMENT = 'wgportal';

    private static function rule_comment(): string {
        $c = get_setting('rule_comment') ?: self::RULE_COMMENT;
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $c)) {
            throw new RuntimeException('設定 rule_comment が不正です (英数字・-・_ で 32 文字まで)。');
        }
        return $c;
    }

    // ---- ルールに付与する comment match ------------------
    private static function comment_opt(): string {
        return '-m comment --comment ' . self::rule_comment();
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

    // ---- 行 id からトンネル内のクライアントアドレスを算出 --
    // 戻り値: [IPv4, IPv6]
    public static function client_addrs(int $id): array {
        $octet = (($id - 1) % 253) + 2;
        return [
            get_setting('subnet') . '.' . $octet,
            get_setting('subnet_ipv6') . dechex($octet),
        ];
    }

    // ---- 全ピアを含む完全サーバー設定ファイル生成 --------
    public static function build_full_server_conf(): string {
        [$up_lines, $down_lines, $peer_blocks] = self::build_server_parts();

        $server_kp  = get_or_create_server_keypair();
        $server_ip  = get_setting('subnet') . '.1';
        $server_ip6 = get_setting('subnet_ipv6') . '1';
        $wg_port    = get_setting('wg_port');

        $conf = "[Interface]\n"
              . "Address = {$server_ip}/24, {$server_ip6}/64\n"
              . "ListenPort = {$wg_port}\n"
              . "PrivateKey = {$server_kp['priv']}\n"
              . "\n";

        foreach ($up_lines   as $rule) $conf .= "PostUp   = {$rule}\n";
        foreach ($down_lines as $rule) $conf .= "PostDown = {$rule}\n";

        return $conf . $peer_blocks;
    }

    // ---- サーバー設定の部品: [PostUp 行, PostDown 行, [Peer] ブロック] --
    // https のポートは nginx が受けるため DNAT / FORWARD を書かない
    // (ピアの定義は方式に関係なく必要)。
    private static function build_server_parts(): array {
        $nic       = get_setting('nic');
        $iface     = self::sanitize_interface(get_setting('wg_interface') ?: 'wg0');

        $db   = get_db();
        $rows = $db->query("SELECT id, port, client_pub, dest_port, proxy_mode FROM wg_configs ORDER BY id ASC")->fetchAll();

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
            [$client_ip, $client_ip6] = self::client_addrs((int)$row['id']);
            $port       = (int)$row['port'];
            $dest       = (int)($row['dest_port'] ?? 0) ?: 80;

            // WireGuardは両方のアドレスを許可
            $peer_blocks .= "\n[Peer]\n"
                         .  "PublicKey = {$row['client_pub']}\n"
                         .  "AllowedIPs = {$client_ip}/32, {$client_ip6}/128\n";

            if (($row['proxy_mode'] ?? 'dnat') === 'https') continue;

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
        }

        return [$up_lines, $down_lines, $peer_blocks];
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
            '/--comment\s+"?' . preg_quote(self::rule_comment(), '/') . '"?(\s|$)/',
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
        // 旧形式のルールを作ったのは既定の目印のポータルだけ
        // (別の目印の検証用ポータルが本番の旧ルールを消さないように)
        if (self::rule_comment() !== self::RULE_COMMENT) return;

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

    // ---- WireGuard 設定ファイルの書き込み -----------------
    // 失敗時はエラーメッセージ、成功時は null を返す
    private static function write_server_conf(string $iface, string $conf): ?string {
        $conf_path = "/etc/wireguard/{$iface}.conf";

        // sudo tee で root 権限が必要なパスへ書き込み
        $proc = proc_open(
            'sudo tee ' . escapeshellarg($conf_path) . ' > /dev/null',
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return "設定ファイルの書き込みに失敗しました: {$conf_path}";
        }
        fwrite($pipes[0], $conf);
        fclose($pipes[0]);
        $err      = stream_get_contents($pipes[2]);
        $writeRet = proc_close($proc);
        if ($writeRet !== 0) {
            return "書き込みエラー: " . trim($err);
        }
        exec('sudo chmod 600 ' . escapeshellarg($conf_path));
        return null;
    }

    // ---- ピアごとの最終ハンドシェイク時刻 -----------------
    // [公開鍵 => UNIX 時刻 (0 = まだ接続していない)]。
    // インターフェースが止まっているなど取得できなければ null
    public static function latest_handshakes(): ?array {
        $iface = self::sanitize_interface(get_setting('wg_interface') ?: 'wg0');
        $out = [];
        exec('sudo wg show ' . escapeshellarg($iface) . ' latest-handshakes 2>/dev/null', $out, $rc);
        if ($rc !== 0) return null;
        $result = [];
        foreach ($out as $line) {
            if (preg_match('#^([A-Za-z0-9+/]{42,44}=?)\s+(\d+)$#', trim($line), $m)) {
                $result[$m[1]] = (int)$m[2];
            }
        }
        return $result;
    }

    // ---- 最終接続の表示 -----------------------------------
    // ['text' => 表示, 'title' => 詳細, 'live' => 最近つながっているか]
    // 切断の通知はないため、最後のハンドシェイクから 3 分以内を「接続中」とする
    // (接続中はおおむね 2 分ごとにハンドシェイクする)
    public static function connection_label(?array $hs, string $pub): array {
        $make = fn(string $text, string $title, bool $live = false) => ['text' => $text, 'title' => $title, 'live' => $live];
        if ($hs === null) return $make('—', 'WireGuard が止まっているか、状態を取得できません');
        $ts = $hs[$pub] ?? null;
        if ($ts === null) return $make('—', 'サーバーの設定にまだ反映されていません');
        if ($ts === 0)    return $make('未接続', 'サーバーの起動後、まだ一度も接続していません');
        $ago = max(0, time() - $ts);
        $at  = date('Y-m-d H:i:s', $ts);
        if ($ago < 180)   return $make('接続中', "最終ハンドシェイク: {$at}", true);
        if ($ago < 3600)  return $make(intdiv($ago, 60) . ' 分前', $at);
        if ($ago < 86400) return $make(intdiv($ago, 3600) . ' 時間前', $at);
        return $make(date('Y-m-d H:i', $ts), $at);
    }

    // 1 ポート分の最終接続
    public static function port_connection(int $port): ?array {
        $row = self::find_row($port);
        if (!$row) return null;
        return self::connection_label(self::latest_handshakes(), (string)$row['client_pub']);
    }

    private static function is_interface_up(string $iface): bool {
        exec('sudo wg show ' . escapeshellarg($iface) . ' 2>/dev/null', $_out, $running);
        return $running === 0;
    }

    // ---- サーバーへ設定を適用 (wg-quick down → up) --------
    // ピアが増減したときに使う。全トンネルが数秒切れる。
    public static function apply_server_config(): array {
        $iface = self::sanitize_interface(get_setting('wg_interface') ?: 'wg0');

        $err = self::write_server_conf($iface, self::build_full_server_conf());
        if ($err !== null) {
            return ['success' => false, 'output' => $err];
        }

        // インターフェースが起動中なら一度止める
        $out1 = [];
        if (self::is_interface_up($iface)) {
            exec('sudo wg-quick down ' . escapeshellarg($iface) . ' 2>&1', $out1, $s1);
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

    // ---- ピアを変えずに iptables ルールだけを入れ替える ----
    // 公開方式や転送先ポートの変更ではピアが変わらないため、
    // wg-quick down/up をせずにルールだけを作り直す (他のトンネルは切れない)。
    // 設定ファイルも書き直すので、次回の wg-quick down では新しいルールが消える。
    // インターフェースが止まっているときは通常の適用にする。
    public static function reapply_rules(): array {
        $iface = self::sanitize_interface(get_setting('wg_interface') ?: 'wg0');
        if (!self::is_interface_up($iface)) {
            return self::apply_server_config();
        }

        $err = self::write_server_conf($iface, self::build_full_server_conf());
        if ($err !== null) {
            return ['success' => false, 'output' => $err];
        }

        [$up_lines] = self::build_server_parts();
        self::flush_stale_iptables($iface);

        $out     = [];
        $success = true;
        foreach ($up_lines as $rule) {
            // PostUp と同じ行を実行する (値は設定値と DB の整数だけから作られる)
            exec('sudo ' . $rule . ' 2>&1', $out, $rc);
            if ($rc !== 0) $success = false;
        }
        $output = trim(implode("\n", $out));

        if ($success) {
            write_log('INFO', "iptables ルールを再適用しました: {$iface}");
        } else {
            write_log('ERROR', "iptables ルールの再適用に失敗: {$iface} - {$output}");
        }
        return ['success' => $success, 'output' => $output];
    }

    // =====================================================
    //  HTTPS 公開 (nginx)
    // =====================================================

    // HTTPS 公開に使うホスト名。管理画面で空欄なら、VPS のアドレスが
    // ホスト名 (IP アドレスでない) のときはそれを使う
    public static function https_host(): string {
        $host = get_setting('https_host');
        if ($host !== '') return $host;
        $vps = trim(get_setting('vps_ip'));
        if ($vps === '' || filter_var($vps, FILTER_VALIDATE_IP) !== false || !str_contains($vps, '.')) return '';
        try {
            return validate_hostname($vps);
        } catch (InvalidArgumentException $e) {
            return '';
        }
    }

    // HTTPS を選べるか: ホスト名が決まっていて、nginx 設定の置き場所がある
    // (置き場所は README の「HTTPS 公開の準備」で作る。HTTPS を使わない
    //  環境では作らないので、VPS のアドレスがホスト名でも選べるようにならない)
    public static function https_ready(): bool {
        if (self::https_host() === '') return false;
        try {
            return is_dir(self::nginx_conf_dir());
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    // [証明書, 秘密鍵] のパス。未入力なら Let's Encrypt の標準の場所
    public static function https_cert_paths(): array {
        $host = self::https_host();
        return [
            get_setting('https_cert')     ?: "/etc/letsencrypt/live/{$host}/fullchain.pem",
            get_setting('https_cert_key') ?: "/etc/letsencrypt/live/{$host}/privkey.pem",
        ];
    }

    // 外部から見た公開 URL
    public static function public_url(int $port, string $mode): string {
        if ($mode === 'https') {
            return 'https://' . self::https_host() . ':' . $port . '/';
        }
        $ip = get_setting('vps_ip');
        if (str_contains($ip, ':')) $ip = "[{$ip}]";
        return "http://{$ip}:{$port}/";
    }

    private static function nginx_conf_dir(): string {
        return validate_abs_path(get_setting('nginx_conf_dir') ?: '/etc/nginx/wgportal.d', 'nginx 設定の置き場所');
    }

    // ---- 1 ポート分の nginx 設定 --------------------------
    // TLS をこのサーバーで終端し、トンネル内の PC へ HTTP で転送する。
    public static function build_nginx_conf(int $port, string $upstream): string {
        $host = validate_hostname(self::https_host());
        if ($host === '') {
            throw new RuntimeException('HTTPS 公開のホスト名が管理画面で設定されていません。');
        }
        [$cert, $key] = self::https_cert_paths();
        validate_abs_path($cert, '証明書のパス');
        validate_abs_path($key, '秘密鍵のパス');

        return "# wireguard-portal が自動生成したファイルです。手で編集しないでください。\n"
             . "# ポート {$port} (HTTPS) → {$upstream} (HTTP)\n"
             . "server {\n"
             . "    listen {$port} ssl;\n"
             . "    listen [::]:{$port} ssl;\n"
             . "    server_name {$host};\n"
             . "\n"
             . "    ssl_certificate     {$cert};\n"
             . "    ssl_certificate_key {$key};\n"
             . "\n"
             . "    # 同じポートに http で来たら https へ移す\n"
             . "    error_page 497 =307 https://{$host}:{$port}\$request_uri;\n"
             . "\n"
             . "    client_max_body_size 1024m;\n"
             . "\n"
             . "    location / {\n"
             . "        proxy_pass http://{$upstream};\n"
             . "        proxy_http_version 1.1;\n"
             . "        proxy_set_header Connection \"\";\n"
             . "        # Host は固定値にする (利用者が送った Host をそのまま渡さない)\n"
             . "        proxy_set_header Host {$host}:{$port};\n"
             . "        proxy_set_header X-Forwarded-Proto https;\n"
             . "        proxy_set_header X-Forwarded-For \$remote_addr;\n"
             . "        proxy_buffering off;\n"
             . "        proxy_request_buffering off;\n"
             . "        proxy_read_timeout 360s;\n"
             . "    }\n"
             . "}\n";
    }

    // ---- DB の https ポートから [ファイル名 => 内容] を作る --
    public static function build_nginx_confs(): array {
        $rows = get_db()->query(
            "SELECT id, port, dest_port FROM wg_configs WHERE proxy_mode = 'https' ORDER BY port ASC"
        )->fetchAll();

        $files = [];
        foreach ($rows as $row) {
            [$client_ip] = self::client_addrs((int)$row['id']);
            $dest = (int)($row['dest_port'] ?? 0) ?: 80;
            $port = (int)$row['port'];
            $files["https-{$port}.conf"] = self::build_nginx_conf($port, "{$client_ip}:{$dest}");
        }
        return $files;
    }

    // ---- nginx 設定を DB の内容に合わせる ------------------
    // 書き込み → nginx -t → (失敗なら元に戻す) → reload。
    // 内容が変わらないときは何もしない (nginx を使わない環境にも影響しない)。
    public static function apply_nginx_config(): array {
        try {
            $dir  = self::nginx_conf_dir();
            $want = self::build_nginx_confs();
        } catch (RuntimeException | InvalidArgumentException $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }

        if (!is_dir($dir)) {
            if (!$want) return ['success' => true, 'output' => ''];
            return ['success' => false, 'output' => "nginx 設定の置き場所 {$dir} がありません。README の手順で作成してください。"];
        }

        $have = [];
        foreach (glob($dir . '/https-*.conf') ?: [] as $path) {
            $name = basename($path);
            if (!preg_match('/^https-\d+\.conf$/', $name)) continue;
            $have[$name] = (string)file_get_contents($path);
        }
        ksort($have);
        ksort($want);
        if ($have === $want) return ['success' => true, 'output' => ''];

        $restore = function () use ($dir, $have, $want): void {
            self::write_nginx_files($dir, $have, $want);
        };

        if (!self::write_nginx_files($dir, $want, $have)) {
            $restore();
            return ['success' => false, 'output' => "nginx 設定を {$dir} に書き込めませんでした (所有者と権限を確認してください)。"];
        }

        exec('sudo nginx -t 2>&1', $out_t, $rc_t);
        if ($rc_t !== 0) {
            $restore();
            $msg = 'nginx の設定チェックに失敗したため、元の設定に戻しました: ' . trim(implode("\n", $out_t));
            write_log('ERROR', $msg);
            return ['success' => false, 'output' => $msg];
        }

        exec('sudo systemctl reload nginx 2>&1', $out_r, $rc_r);
        if ($rc_r !== 0) {
            $restore();
            $msg = 'nginx の再読み込みに失敗したため、元の設定に戻しました: ' . trim(implode("\n", $out_r));
            write_log('ERROR', $msg);
            return ['success' => false, 'output' => $msg];
        }

        $ports = array_map(fn($n) => (int)substr($n, 6), array_keys($want));
        write_log('INFO', 'nginx 設定を適用しました (HTTPS のポート: ' . ($ports ? implode(', ', $ports) : 'なし') . ')');
        return ['success' => true, 'output' => ''];
    }

    // $files を書き込み、$old にあって $files にないファイルを消す
    private static function write_nginx_files(string $dir, array $files, array $old): bool {
        $ok = true;
        foreach ($files as $name => $content) {
            // *.conf に一致しない名前で書いてから置き換える (書きかけを nginx に読ませない)
            $tmp = "{$dir}/.{$name}.tmp";
            if (@file_put_contents($tmp, $content) === false || !@rename($tmp, "{$dir}/{$name}")) {
                @unlink($tmp);
                $ok = false;
            }
        }
        foreach (array_keys(array_diff_key($old, $files)) as $name) {
            if (file_exists("{$dir}/{$name}") && !@unlink("{$dir}/{$name}")) $ok = false;
        }
        return $ok;
    }

    // ---- nginx と WireGuard の両方を反映 -------------------
    // $nginx_first: true  = HTTPS が増える向き (nginx が待ち受けてから DNAT を外す)
    //               false = HTTPS が減る向き (DNAT を戻してから nginx を外す)
    //               DNAT が残っている間は外部からの通信を DNAT が先に取るので、
    //               この順なら切り替えで途切れない。
    // $peers_changed: false ならピアは同じなので iptables だけを入れ替える。
    public static function apply_all(bool $nginx_first = true, bool $peers_changed = true): array {
        $wg    = fn() => $peers_changed ? self::apply_server_config() : self::reapply_rules();
        $nginx = fn() => self::apply_nginx_config();
        $steps = $nginx_first ? [$nginx, $wg] : [$wg, $nginx];

        $outputs = [];
        foreach ($steps as $step) {
            $r = $step();
            if ($r['output'] !== '') $outputs[] = $r['output'];
            if (!$r['success']) {
                return ['success' => false, 'output' => implode("\n", $outputs)];
            }
        }
        return ['success' => true, 'output' => implode("\n", $outputs)];
    }

    // =====================================================
    //  ポートごとの操作
    // =====================================================

    private static function find_row(int $port): ?array {
        $stmt = get_db()->prepare("SELECT * FROM wg_configs WHERE port = ?");
        $stmt->execute([$port]);
        return $stmt->fetch() ?: null;
    }

    public static function exists(int $port): bool {
        return self::find_row($port) !== null;
    }

    // ---- 管理トークンの照合 -------------------------------
    public static function verify_token(int $port, string $token): bool {
        $row  = self::find_row($port);
        $hash = (string)($row['delete_token'] ?? '');
        return $token !== '' && $hash !== '' && password_verify($token, $hash);
    }

    // ---- 管理トークンの再発行 (管理者用) -------------------
    public static function reset_token(int $port): ?string {
        if (!self::find_row($port)) return null;
        [$plain, $hash] = generate_manage_token();
        get_db()->prepare("UPDATE wg_configs SET delete_token = ? WHERE port = ?")->execute([$hash, $port]);
        write_log('INFO', "ポート {$port} の管理トークンを発行しました");
        return $plain;
    }

    // ---- 現在の設定 (表示用) -------------------------------
    public static function port_info(int $port): ?array {
        $row = self::find_row($port);
        if (!$row) return null;
        [$client_ip] = self::client_addrs((int)$row['id']);
        $mode = $row['proxy_mode'] ?: 'dnat';
        return [
            'port'       => $port,
            'dest_port'  => (int)($row['dest_port'] ?? 0) ?: 80,
            'proxy_mode' => $mode,
            'public_url' => self::public_url($port, $mode),
            'client_ip'  => $client_ip,
            'owner_name' => (string)($row['owner_name'] ?? ''),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    // ---- 登録者の名前を変更 (サーバーへの反映は不要) -------
    // 変わったら true
    public static function set_owner_name(int $port, string $name): bool {
        $row = self::find_row($port);
        if (!$row) {
            throw new InvalidArgumentException("ポート {$port} は登録されていません。");
        }
        if ((string)($row['owner_name'] ?? '') === $name) return false;
        get_db()->prepare("UPDATE wg_configs SET owner_name = ?, updated_at = CURRENT_TIMESTAMP WHERE port = ?")
                ->execute([$name, $port]);
        write_log('INFO', "ポート {$port} の登録者名を変更しました");
        return true;
    }

    // ---- 公開方式が選べるか (HTTPS は管理者の設定が必要) ---
    private static function assert_mode_usable(string $mode): void {
        if ($mode === 'https' && !self::https_ready()) {
            throw new InvalidArgumentException('HTTPS 公開は管理者が設定していないため選べません。');
        }
    }

    // ---- 鍵を変えずに公開方式・転送先ポートを変更 ----------
    public static function update_port(int $port, string $mode, int $dest_port): array {
        $row = self::find_row($port);
        if (!$row) {
            throw new InvalidArgumentException("ポート {$port} は登録されていません。");
        }
        $mode     = validate_proxy_mode($mode);
        $old_mode = $row['proxy_mode'] ?: 'dnat';
        $old_dest = (int)($row['dest_port'] ?? 0) ?: 80;

        if ($mode === $old_mode && $dest_port === $old_dest) {
            return ['changed' => false, 'applied' => null, 'info' => self::port_info($port)];
        }
        if ($mode !== $old_mode) self::assert_mode_usable($mode);

        // DNAT から nginx に移すポートは、ほかのサービスが待ち受けていないこと
        // (DNAT のポートは LISTEN に現れないので、ここで見つかるのは別のサービス)
        if ($mode === 'https' && $old_mode !== 'https' && get_setting('check_listen') !== '0') {
            $listening = get_listening_tcp_ports();
            if ($listening !== null && in_array($port, $listening, true)) {
                throw new InvalidArgumentException(
                    "ポート {$port} はサーバー上の別のサービスが使用中のため、HTTPS に切り替えられません。"
                );
            }
        }

        $save = function (string $m, int $d) use ($port): void {
            get_db()->prepare("UPDATE wg_configs SET proxy_mode = ?, dest_port = ?, updated_at = CURRENT_TIMESTAMP WHERE port = ?")
                    ->execute([$m, $d, $port]);
        };
        $save($mode, $dest_port);
        write_log('INFO', "ポート {$port} の設定を変更しました (方式 {$old_mode}→{$mode}, 転送先 {$old_dest}→{$dest_port})");

        $applied = null;
        if (get_setting('auto_apply') === '1') {
            $nginx_first = ($mode === 'https');
            $applied = self::apply_all($nginx_first, false);
            if (!$applied['success']) {
                // 変更前の状態に戻す (逆の順序で反映し直す)
                $save($old_mode, $old_dest);
                self::apply_all(!$nginx_first, false);
                write_log('ERROR', "ポート {$port} の設定変更を反映できなかったため元に戻しました: " . $applied['output']);
            }
        }

        return ['changed' => true, 'applied' => $applied, 'info' => self::port_info($port)];
    }

    // ---- ポートの削除 -------------------------------------
    // 見つからなければ null。見つかれば ['applied' => 自動適用の結果 or null]
    public static function remove(int $port): ?array {
        if (!self::find_row($port)) return null;
        get_db()->prepare("DELETE FROM wg_configs WHERE port = ?")->execute([$port]);

        $applied = null;
        if (get_setting('auto_apply') === '1') {
            $applied = self::apply_all(false, true);
            if ($applied['success']) {
                write_log('INFO', "ポート {$port} 削除後の自動適用完了");
            } else {
                write_log('ERROR', "ポート {$port} 削除後の自動適用失敗: " . $applied['output']);
            }
        }
        return ['applied' => $applied];
    }

    // ---- 既存ポートの設定を鍵を変えずに再生成 ------------
    public static function rebuild_client_conf(int $port): ?array {
        $row = self::find_row($port);
        if (!$row) return null;

        $server_kp   = get_or_create_server_keypair();
        $server_ip   = get_setting('subnet') . '.1';
        [$client_ip] = self::client_addrs((int)$row['id']);

        $client_conf = self::build_client_conf(
            $row['client_priv'], $server_kp['pub'], $client_ip, $server_ip
        );
        return self::port_info($port) + ['client_conf' => $client_conf];
    }

    // ---- メイン: ポートに対してキーペアを発行/再発行 ----
    // $dest_port はトンネル内 PC 側の転送先ポート、$mode は公開方式 (dnat / https)。
    // 管理トークンは削除モードに関係なく毎回発行する (設定変更画面で使う)。
    public static function issue(int $port, int $dest_port = 80, string $mode = 'dnat', string $owner_name = ''): array {
        $mode = validate_proxy_mode($mode);
        self::assert_mode_usable($mode);

        $db          = get_db();
        $server_kp   = get_or_create_server_keypair();
        $client_kp   = wg_generate_keypair();
        $server_ip   = get_setting('subnet') . '.1';

        [$plaintext_token, $hashed_token] = generate_manage_token();

        // UPSERT (server_priv/pub はグローバル鍵を記録)
        $db->prepare("
            INSERT INTO wg_configs (port, dest_port, proxy_mode, owner_name, client_priv, client_pub, server_priv, server_pub, delete_token, updated_at)
            VALUES (:port, :dport, :mode, :owner, :cp, :cP, :sp, :sP, :dt, CURRENT_TIMESTAMP)
            ON CONFLICT(port) DO UPDATE SET
                dest_port    = excluded.dest_port,
                proxy_mode   = excluded.proxy_mode,
                owner_name   = excluded.owner_name,
                client_priv  = excluded.client_priv,
                client_pub   = excluded.client_pub,
                server_priv  = excluded.server_priv,
                server_pub   = excluded.server_pub,
                delete_token = excluded.delete_token,
                updated_at   = CURRENT_TIMESTAMP
        ")->execute([
            ':port'  => $port,
            ':dport' => $dest_port,
            ':mode'  => $mode,
            ':owner' => $owner_name,
            ':cp'    => $client_kp['priv'],
            ':cP'    => $client_kp['pub'],
            ':sp'    => $server_kp['priv'],
            ':sP'    => $server_kp['pub'],
            ':dt'    => $hashed_token,
        ]);

        // UPSERT後に実際のidを取得してIPを算出（削除後の飛び番対策）
        $stmt = $db->prepare("SELECT id FROM wg_configs WHERE port = ?");
        $stmt->execute([$port]);
        [$client_ip] = self::client_addrs((int)$stmt->fetchColumn());

        $client_conf = self::build_client_conf(
            $client_kp['priv'], $server_kp['pub'], $client_ip, $server_ip
        );
        // UPSERT 後に呼ぶことで今回のピアを含む完全設定を返す
        $server_conf = self::build_full_server_conf();
        $setup_cmds  = self::build_setup_commands($port);

        write_log('INFO', "ポート {$port} の設定を生成しました (client_ip={$client_ip}, dest_port={$dest_port}, mode={$mode})");

        $applied = null;
        if (get_setting('auto_apply') === '1') {
            $applied = self::apply_all(true, true);
            if ($applied['success']) {
                write_log('INFO', "ポート {$port} 自動適用完了");
            } else {
                write_log('ERROR', "ポート {$port} 自動適用失敗: " . $applied['output']);
            }
        }

        return [
            'port'         => $port,
            'dest_port'    => $dest_port,
            'proxy_mode'   => $mode,
            'public_url'   => self::public_url($port, $mode),
            'client_ip'    => $client_ip,
            'client_conf'  => $client_conf,
            'server_conf'  => $server_conf,
            'setup_cmds'   => $setup_cmds,
            'applied'      => $applied,
            'manage_token' => $plaintext_token,
        ];
    }
}
