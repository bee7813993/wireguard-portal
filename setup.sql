-- WireGuard Portal データベース初期化
CREATE TABLE IF NOT EXISTS wg_configs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    port        INTEGER NOT NULL UNIQUE,
    client_priv TEXT    NOT NULL,
    client_pub  TEXT    NOT NULL,
    server_priv TEXT    NOT NULL,
    server_pub  TEXT    NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('vps_ip',     '203.0.113.1'),
    ('wg_port',    '51820'),
    ('nic',        'eth0'),
    ('subnet',     '10.0.0'),
    ('admin_pass', '$2y$10$examplehashchangethis');
