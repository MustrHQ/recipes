<?php
/**
 * Database upgrades, keyed by schema version. Each entry runs once, in order, on the first
 * request after new files are uploaded (and from Admin → Updates). Only ever add steps to
 * the end; never edit one that has shipped. Fresh installs create the full schema from
 * inc/schema.php and start at the newest number here.
 */
return [
    1 => [],  // 1.0.0-pre: recipes, ingredients, steps, brands, settings, admins
    2 => [    // 1.0.0: sign-in throttling
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY kind_ip_at (kind, ip, at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
