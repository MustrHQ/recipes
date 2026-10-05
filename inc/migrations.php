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
    3 => [    // 1.1.0: use-by labels
        "CREATE TABLE IF NOT EXISTS label_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            brand_id INT UNSIGNED NULL,
            recipe_id INT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            kind ENUM('made','opened','defrosted') NOT NULL DEFAULT 'made',
            shelf_value SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            shelf_unit ENUM('hours','days') NOT NULL DEFAULT 'days',
            storage VARCHAR(60) NOT NULL DEFAULT '',
            allergens VARCHAR(255) NOT NULL DEFAULT '',
            ask_supplier TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            KEY brand_id (brand_id),
            KEY recipe_id (recipe_id),
            CONSTRAINT fk_label_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
            CONSTRAINT fk_label_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
