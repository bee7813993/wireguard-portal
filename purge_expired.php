<?php
// =========================================================
//  purge_expired.php  – 期限切れのお試し用ポートを削除する (CLI 専用)
//  画面を開いたときにも削除されるが、誰も開かない間も期限どおりに
//  消したいときは www-data の cron で定期的に実行する:
//    */10 * * * * php /path/to/wireguard-portal/purge_expired.php
// =========================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wg_manager.php';

$ports = WgManager::purge_expired();
if ($ports) {
    echo '削除しました: ' . implode(', ', $ports) . PHP_EOL;
}
