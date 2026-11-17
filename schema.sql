-- InfinityFree: CREATE the database in your InfinityFree panel FIRST.
-- In phpMyAdmin, SELECT THAT DATABASE, then import this file.
-- Do not use CREATE DATABASE or USE statements on InfinityFree.

CREATE TABLE IF NOT EXISTS dc_admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dc_rooms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  call_type ENUM('audio','video') NOT NULL,
  host_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  guest_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  guest_name VARCHAR(60) DEFAULT NULL,
  status ENUM('waiting','joined','active','ended') NOT NULL DEFAULT 'waiting',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  ended_at DATETIME DEFAULT NULL,
  INDEX dc_rooms_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dc_signals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  sender_role ENUM('host','guest') NOT NULL,
  kind ENUM('ready','offer','answer','ice') NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX dc_room_signal (room_id, id),
  CONSTRAINT dc_room_signals_fk FOREIGN KEY (room_id)
     REFERENCES dc_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A unique random initial password is included in README.md (outside public/).
-- INSERT IGNORE avoids resetting a changed password when schema.sql is re-imported.
INSERT IGNORE INTO dc_admins (id, username, password_hash)
VALUES (1, 'admin', '$2y$12$56h5s3UKM7iKEbSitB8dQewHOY0zMESC39HHtNN4NpXulz26RnIcu');
