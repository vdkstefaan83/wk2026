-- Run once on existing installs:
--   mysql wk2026 < migrations/alter_topscorer_custom.sql
ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS topscorer_custom_name VARCHAR(128) NULL AFTER topscorer_player_id,
    ADD COLUMN IF NOT EXISTS tiebreaker_value      INT          NULL AFTER topscorer_custom_name;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
('tiebreaker_question',      'Hoeveel doelpunten worden er in totaal gemaakt tijdens het toernooi?'),
('tiebreaker_correct_value', '');
