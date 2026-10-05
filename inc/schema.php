<?php
/** Full schema for a fresh install. Used by install.php. Later changes go in inc/migrations.php too. */
return [
    "CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(60) PRIMARY KEY,
        v TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS brands (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        colour CHAR(7) NOT NULL DEFAULT '#01216C',
        sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS recipes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        brand_id INT UNSIGNED NULL,
        code VARCHAR(20) NOT NULL DEFAULT '',
        name VARCHAR(140) NOT NULL,
        description TEXT NULL,
        yield_text VARCHAR(80) NOT NULL DEFAULT '',
        prep_minutes SMALLINT UNSIGNED NULL,
        cook_minutes SMALLINT UNSIGNED NULL,
        allergens VARCHAR(255) NOT NULL DEFAULT '',
        diets VARCHAR(120) NOT NULL DEFAULT '',
        image VARCHAR(80) NULL,
        notes TEXT NULL,
        published TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY brand_id (brand_id),
        CONSTRAINT fk_recipe_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS ingredients (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipe_id INT UNSIGNED NOT NULL,
        sort SMALLINT NOT NULL DEFAULT 0,
        name VARCHAR(120) NOT NULL,
        qty VARCHAR(20) NOT NULL DEFAULT '',
        unit VARCHAR(20) NOT NULL DEFAULT '',
        note VARCHAR(160) NOT NULL DEFAULT '',
        image VARCHAR(80) NULL,
        KEY recipe_id (recipe_id),
        CONSTRAINT fk_ing_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS steps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipe_id INT UNSIGNED NOT NULL,
        sort SMALLINT NOT NULL DEFAULT 0,
        title VARCHAR(120) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        tip VARCHAR(255) NOT NULL DEFAULT '',
        timer_seconds INT UNSIGNED NULL,
        image VARCHAR(80) NULL,
        KEY recipe_id (recipe_id),
        CONSTRAINT fk_step_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS login_attempts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(10) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY kind_ip_at (kind, ip, at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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
];
