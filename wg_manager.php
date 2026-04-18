<?php
// =========================================================
//  wg_manager.php  – 設定ファイル生成 & システム適用
// =========================================================
require_once __DIR__ . '/config.php';

class WgManager {

    // ---- IPアドレスのオクテット割り当て -----------------
    // port → 固定の client IP を割り当て (サブネット内で衝突しないよう管理)
    // .1 = サーバー固定, .2〜.254 = クライアント用
    private static function get_client_ip(int $port): string {
        $db = get_db();

        // 既存エントリがあればそのIPを返す
        $row = $db->prepare("SELECT rowid FROM wg_configs WHERE port = ?");
        $row->execute([$port]);
        $existing = $row->fetch();

        if ($existing) {
            // rowid ベースで 2〜254 を割り当て
            $octet = (($existing['rowid'] - 1) % 253) + 2;
        } else {
            // 新規: 現在の最大 rowid + 1 を先読み
            $max = (int)$db->query("SELECT IFNULL(MAX(rowid),0) FROM wg_configs")->fetchColumn();
            $octet = ($max % 253) + 2;
        }
        return get_setting('subnet') . '.' . $octet;
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

        return "[Interface]\n"
             . "PrivateKey = {$client_priv}\n"
             . "Address = {$client_ip}/24\n"
             . "DNS = 1.1.1.1\n"
             . "\n"
             . "[Peer]\n"
             . "PublicKey = {$server_pub}\n"
             . "Endpoint = {$vps_ip}:{$wg_port}\n"
             . "AllowedIPs = {$server_ip}/32\n"
             . "PersistentKeepalive = 25\n";
    }

    // ---- VPS サーバー用 wg0.conf 生成 -------------------
    public static function build_server_conf_snippet(
        string $server_priv,
        string $client_pub,
        string $server_ip,
        string $client_ip,
        int    $ext_port
    ): string {
        $wg_port = get_setting('wg_port');
        $nic     = get_setting('nic');

        $post_up   = self::iptables_rules($nic, $ext_port, $client_ip, '-A', '-A');
        $post_down = self::iptables_rules($nic, $ext_port, $client_ip, '-D', '-D');

        return "[Interface]\n"
             . "Address = {$server_ip}/24\n"
             . "ListenPort = {$wg_port}\n"
             . "PrivateKey = {$server_priv}\n"
             . "\n"
             . "PostUp   = {$post_up[0]}\n"
             . "PostUp   = {$post_up[1]}\n"
             . "PostUp   = {$post_up[2]}\n"
             . "PostDown = {$post_down[0]}\n"
             . "PostDown = {$post_down[1]}\n"
             . "PostDown = {$post_down[2]}\n"
             . "\n"
             . "[Peer]\n"
             . "PublicKey = {$client_pub}\n"
             . "AllowedIPs = {$client_ip}/32\n";
    }

    private static function iptables_rules(
        string $nic,
        int    $ext_port,
        string $client_ip,
        string $nat_flag,
        string $fwd_flag
    ): array {
        return [
            "iptables -t nat {$nat_flag} PREROUTING -i {$nic} -p tcp --dport {$ext_port} -j DNAT --to-destination {$client_ip}:80",
            "iptables {$fwd_flag} FORWARD -p tcp -d {$client_ip} --dport 80 -j ACCEPT",
            "iptables -t nat {$nat_flag} POSTROUTING -o wg0 -j MASQUERADE",
        ];
    }

    // ---- セットアップコマンド生成 -------------------------
    public static function build_setup_commands(int $ext_port): string {
        $wg_port = get_setting('wg_port');
        return "# 1. WireGuardインストール\n"
             . "sudo apt update && sudo apt install wireguard -y\n\n"
             . "# 2. IPフォワーディングを有効化\n"
             . "echo \"net.ipv4.ip_forward=1\" | sudo tee -a /etc/sysctl.conf\n"
             . "sudo sysctl -p\n\n"
             . "# 3. wg0.conf を配置 (上の内容を保存)\n"
             . "sudo nano /etc/wireguard/wg0.conf\n\n"
             . "# 4. 起動・自動起動\n"
             . "sudo wg-quick up wg0\n"
             . "sudo systemctl enable wg-quick@wg0\n\n"
             . "# 5. ファイアウォール開放\n"
             . "sudo ufw allow {$wg_port}/udp\n"
             . "sudo ufw allow {$ext_port}/tcp\n\n"
             . "# 6. 状態確認\n"
             . "sudo wg show\n";
    }

    // ---- メイン: ポートに対してキーペアを発行/再発行 ----
    public static function issue(int $port): array {
        $db        = get_db();
        $client_kp = wg_generate_keypair();
        $server_kp = wg_generate_keypair();
        $subnet    = get_setting('subnet');
        $server_ip = $subnet . '.1';

        // 先にclient_ipを決定するため一時的にDB操作順序を調整
        $client_ip = self::get_client_ip($port);

        // UPSERT
        $db->prepare("
            INSERT INTO wg_configs (port, client_priv, client_pub, server_priv, server_pub, updated_at)
            VALUES (:port, :cp, :cP, :sp, :sP, CURRENT_TIMESTAMP)
            ON CONFLICT(port) DO UPDATE SET
                client_priv = excluded.client_priv,
                client_pub  = excluded.client_pub,
                server_priv = excluded.server_priv,
                server_pub  = excluded.server_pub,
                updated_at  = CURRENT_TIMESTAMP
        ")->execute([
            ':port' => $port,
            ':cp'   => $client_kp['priv'],
            ':cP'   => $client_kp['pub'],
            ':sp'   => $server_kp['priv'],
            ':sP'   => $server_kp['pub'],
        ]);

        $client_conf = self::build_client_conf(
            $client_kp['priv'], $server_kp['pub'], $client_ip, $server_ip
        );
        $server_conf = self::build_server_conf_snippet(
            $server_kp['priv'], $client_kp['pub'], $server_ip, $client_ip, $port
        );
        $setup_cmds = self::build_setup_commands($port);

        return [
            'port'        => $port,
            'client_ip'   => $client_ip,
            'client_conf' => $client_conf,
            'server_conf' => $server_conf,
            'setup_cmds'  => $setup_cmds,
        ];
    }
}
