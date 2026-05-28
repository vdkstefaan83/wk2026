-- WK2026 schema
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS predictions;
DROP TABLE IF EXISTS forms;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS team_groups;
DROP TABLE IF EXISTS teams;
DROP TABLE IF EXISTS players;
DROP TABLE IF EXISTS email_templates;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email           VARCHAR(255) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NULL,
    auth_provider   ENUM('db','keycloak') NOT NULL DEFAULT 'db',
    oidc_sub        VARCHAR(255) NULL,
    is_admin        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_email (email),
    UNIQUE KEY uniq_oidc (oidc_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    `key`       VARCHAR(64) NOT NULL,
    `value`     TEXT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_templates (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`       VARCHAR(64) NOT NULL,
    subject     VARCHAR(255) NOT NULL,
    body_html   MEDIUMTEXT NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(2) NOT NULL,
    name        VARCHAR(32) NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teams (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(64) NOT NULL,
    iso3        VARCHAR(3) NOT NULL,
    flag_emoji  VARCHAR(16) NULL,
    group_id    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_iso (iso3),
    KEY idx_group (group_id),
    CONSTRAINT fk_team_group FOREIGN KEY (group_id) REFERENCES team_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE players (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(128) NOT NULL,
    team_id     INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_team (team_id),
    CONSTRAINT fk_player_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matches (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    stage           ENUM('group','r32','r16','qf','sf','final') NOT NULL,
    match_number    SMALLINT UNSIGNED NOT NULL,
    group_id        INT UNSIGNED NULL,
    home_team_id    INT UNSIGNED NULL,
    away_team_id    INT UNSIGNED NULL,
    kickoff_at      DATETIME NULL,
    venue           VARCHAR(128) NULL,
    actual_home_goals TINYINT UNSIGNED NULL,
    actual_away_goals TINYINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_match_number (match_number),
    KEY idx_stage (stage),
    KEY idx_group (group_id),
    CONSTRAINT fk_match_group FOREIGN KEY (group_id) REFERENCES team_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_match_home FOREIGN KEY (home_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_match_away FOREIGN KEY (away_team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One prediction form per user-attempt
CREATE TABLE forms (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    label           VARCHAR(128) NOT NULL DEFAULT 'Mijn voorspelling',
    status          ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
    submitted_at    DATETIME NULL,
    paid_at         DATETIME NULL,
    paid_amount     DECIMAL(8,2) NULL,
    paid_note       VARCHAR(255) NULL,
    pdf_path        VARCHAR(255) NULL,
    -- knockout slot picks (team_id per slot)
    topscorer_player_id INT UNSIGNED NULL,
    winner_team_id      INT UNSIGNED NULL,
    score              INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_status (status),
    CONSTRAINT fk_form_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_topscorer FOREIGN KEY (topscorer_player_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_winner FOREIGN KEY (winner_team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-match prediction (group-stage scores + knockout winners)
CREATE TABLE predictions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_id         INT UNSIGNED NOT NULL,
    match_id        INT UNSIGNED NULL,
    -- group stage:
    home_goals      TINYINT UNSIGNED NULL,
    away_goals      TINYINT UNSIGNED NULL,
    -- knockout: pick the team that advances from this slot
    stage           ENUM('group','r32','r16','qf','sf','final') NOT NULL,
    slot_code       VARCHAR(16) NOT NULL DEFAULT '',
    team_id         INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_form_match (form_id, match_id, slot_code),
    KEY idx_form (form_id),
    KEY idx_match (match_id),
    CONSTRAINT fk_pred_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_pred_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE SET NULL,
    CONSTRAINT fk_pred_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
