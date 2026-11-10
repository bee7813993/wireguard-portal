-- WireGuard Portal データベース初期化
CREATE TABLE IF NOT EXISTS wg_configs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    port        INTEGER NOT NULL UNIQUE,
    dest_port   INTEGER NOT NULL DEFAULT 80,
    proxy_mode  TEXT    NOT NULL DEFAULT 'dnat',
    owner_name  TEXT    NOT NULL DEFAULT '',
    client_priv TEXT    NOT NULL,
    client_pub  TEXT    NOT NULL,
    server_priv TEXT    NOT NULL,
    server_pub  TEXT    NOT NULL,
    delete_token TEXT   DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('vps_ip',            '203.0.113.1'),
    ('wg_port',           '51820'),
    ('nic',               'eth0'),
    ('subnet',            '10.0.0'),
    ('default_dest_port', '80'),
    ('admin_pass',        '$2y$10$examplehashchangethis');
