-- NSBM SportsHub v1 - genuine MySQL 8.0.16+ (CHECK constraints enforced).
-- Run against an explicitly created, dedicated database. No database is selected here.
-- All DATETIME values are UTC. No DROP, TRUNCATE or cascading history deletion.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(128) NOT NULL PRIMARY KEY,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student','admin') NOT NULL DEFAULT 'student',
    student_id VARCHAR(100) NULL,
    faculty VARCHAR(100) NULL,
    notifications BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT uq_users_student_id UNIQUE (student_id),
    CONSTRAINT chk_users_role CHECK (role IN ('student','admin')),
    CONSTRAINT chk_users_name CHECK (CHAR_LENGTH(TRIM(name)) BETWEEN 2 AND 80),
    CONSTRAINT chk_users_notifications CHECK (notifications IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clubs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sport VARCHAR(60) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    coach VARCHAR(100) NOT NULL,
    location VARCHAR(100) NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_clubs_sport_name (sport,name),
    CONSTRAINT chk_clubs_capacity CHECK (capacity BETWEEN 1 AND 1000),
    CONSTRAINT chk_clubs_name CHECK (CHAR_LENGTH(TRIM(name)) BETWEEN 2 AND 100),
    CONSTRAINT chk_clubs_sport CHECK (CHAR_LENGTH(TRIM(sport)) BETWEEN 2 AND 60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memberships (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    club_id INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT chk_memberships_status CHECK (status IN ('pending','approved','rejected')),
    CONSTRAINT uq_memberships_user_club UNIQUE (user_id,club_id),
    INDEX idx_memberships_club_status (club_id,status),
    INDEX idx_memberships_user_status (user_id,status),
    CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_memberships_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    type ENUM('training','match','event') NOT NULL,
    starts_at DATETIME(3) NOT NULL,
    ends_at DATETIME(3) NOT NULL,
    location VARCHAR(100) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_schedules_start (starts_at),
    INDEX idx_schedules_club_start (club_id,starts_at),
    CONSTRAINT fk_schedules_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_schedules_type CHECK (type IN ('training','match','event')),
    CONSTRAINT chk_schedules_dates CHECK (ends_at > starts_at),
    CONSTRAINT chk_schedules_title CHECK (CHAR_LENGTH(TRIM(title)) BETWEEN 2 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tournaments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sport VARCHAR(60) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    location VARCHAR(100) NOT NULL,
    starts_at DATETIME(3) NOT NULL,
    ends_at DATETIME(3) NOT NULL,
    registration_deadline DATETIME(3) NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_tournaments_sport_start (sport,starts_at),
    CONSTRAINT chk_tournaments_capacity CHECK (capacity BETWEEN 1 AND 1000),
    CONSTRAINT chk_tournaments_dates CHECK (ends_at > starts_at AND registration_deadline < starts_at),
    CONSTRAINT chk_tournaments_name CHECK (CHAR_LENGTH(TRIM(name)) BETWEEN 2 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tournament_id INT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT uq_registrations_user_tournament UNIQUE (user_id,tournament_id),
    INDEX idx_registrations_tournament (tournament_id),
    CONSTRAINT fk_registrations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_registrations_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    home_team VARCHAR(100) NOT NULL,
    away_team VARCHAR(100) NOT NULL,
    starts_at DATETIME(3) NOT NULL,
    home_score SMALLINT UNSIGNED NULL,
    away_score SMALLINT UNSIGNED NULL,
    status ENUM('scheduled','completed') NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_matches_tournament_start (tournament_id,starts_at),
    CONSTRAINT fk_matches_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_matches_status CHECK (status IN ('scheduled','completed')),
    CONSTRAINT chk_matches_teams CHECK (LOWER(TRIM(home_team)) <> LOWER(TRIM(away_team))),
    CONSTRAINT chk_matches_scores CHECK (
        (status = 'scheduled' AND home_score IS NULL AND away_score IS NULL)
        OR (status = 'completed' AND home_score IS NOT NULL AND away_score IS NOT NULL AND home_score <= 999 AND away_score <= 999)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facilities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('facility','equipment') NOT NULL,
    location VARCHAR(100) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_facilities_type (type),
    CONSTRAINT chk_facilities_type CHECK (type IN ('facility','equipment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    facility_id INT UNSIGNED NOT NULL,
    starts_at DATETIME(3) NOT NULL,
    ends_at DATETIME(3) NOT NULL,
    purpose VARCHAR(300) NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_bookings_facility_active_interval (facility_id,status,starts_at,ends_at),
    INDEX idx_bookings_user_start (user_id,starts_at),
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_bookings_status CHECK (status IN ('pending','approved','rejected','cancelled')),
    CONSTRAINT chk_bookings_dates CHECK (ends_at > starts_at AND TIMESTAMPDIFF(MICROSECOND,starts_at,ends_at) <= 14400000000),
    CONSTRAINT chk_bookings_purpose CHECK (CHAR_LENGTH(TRIM(purpose)) BETWEEN 3 AND 300)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participation (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    schedule_id INT UNSIGNED NULL,
    registration_id INT UNSIGNED NULL,
    event_label VARCHAR(100) NOT NULL,
    sport VARCHAR(60) NOT NULL,
    occurred_at DATETIME(3) NOT NULL,
    status ENUM('attended','registered') NOT NULL,
    CONSTRAINT uq_participation_user_schedule_status UNIQUE (user_id,schedule_id,status),
    CONSTRAINT uq_participation_registration_status UNIQUE (registration_id,status),
    INDEX idx_participation_user_date (user_id,occurred_at),
    INDEX idx_participation_sport_status (sport,status),
    CONSTRAINT fk_participation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_participation_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_participation_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_participation_status CHECK (status IN ('attended','registered')),
    CONSTRAINT chk_participation_single_source CHECK (schedule_id IS NULL OR registration_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operational support tables are private and never returned as raw API records.
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_activity_user_created (user_id,created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    window_start DATETIME(3) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_login_attempts_expiry (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
