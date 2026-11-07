<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;
use RuntimeException;

final class DB
{
    private static ?PDO $pdo = null;
    private static ?string $driver = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = (string)(Config::get('db_dsn') ?: '');
        if ($dsn === '') {
            $stateDir = Config::stateDir();
            if (!is_dir($stateDir)) {
                mkdir($stateDir, 0750, true);
            }
            $dsn = 'sqlite:' . Config::get('db_path');
        }

        $pdo = new PDO($dsn, Config::get('db_user') ?: null, Config::get('db_password') ?: null);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if (self::driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::registerSqliteFunctions($pdo);
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    private static function registerSqliteFunctions(PDO $pdo): void
    {
        if (!method_exists($pdo, 'sqliteCreateFunction')) {
            return;
        }

        $flags = defined('PDO::SQLITE_DETERMINISTIC') ? PDO::SQLITE_DETERMINISTIC : 0;
        $pdo->sqliteCreateFunction(
            'sesame_portal_lower',
            static function (mixed $value): string {
                $text = (string)($value ?? '');
                return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
            },
            1,
            $flags
        );
    }

    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        $dsn = (string)(Config::get('db_dsn') ?: 'sqlite:' . Config::get('db_path'));
        self::$driver = strtolower(strtok($dsn, ':') ?: 'sqlite');
        return self::$driver;
    }

    public static function migrate(): void
    {
        $pdo = self::pdo();
        foreach (self::schemaStatements() as $statement) {
            $pdo->exec($statement);
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS remembered_logins (
            token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id BIGINT NOT NULL,
            credential_hash VARCHAR(64) NOT NULL,
            created_at BIGINT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        self::ensureIndex('remembered_logins', 'idx_remembered_logins_user', 'user_id');
        $pdo->exec('CREATE TABLE IF NOT EXISTS rbt_authbackend_secrets (
            secret_id BIGINT NOT NULL PRIMARY KEY,
            rbt_id VARCHAR(128) NOT NULL,
            secret TEXT NOT NULL,
            valid_from BIGINT NOT NULL,
            expires_at BIGINT NOT NULL
        )');
        self::ensureIndex('rbt_authbackend_secrets', 'idx_rbt_authbackend_expires', 'expires_at');
        VideoWalls::migrate();
        self::ensureIndex('video_walls', 'idx_video_walls_user', 'user_id');

        self::ensureColumn('users', 'admin_comment', 'TEXT');
        self::ensureColumn('users', 'hide_archive', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('users', 'ptz_allowed', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('users', 'mosaic_columns', 'INTEGER NOT NULL DEFAULT 3');
        self::ensureColumn('users', 'mosaic_preview_refresh', 'VARCHAR(16)');
        self::ensureColumn('portal_groups', 'parent_group_id', self::driver() === 'mysql' ? 'BIGINT NULL' : 'INTEGER');
        self::dropPortalGroupNameUniqueConstraint();
        self::ensureIndex('camera_groups', 'idx_camera_groups_group', 'group_id');
        self::ensureIndex('user_groups', 'idx_user_groups_group', 'group_id');
        self::ensureIndex('portal_groups', 'idx_portal_groups_parent', 'parent_group_id');
        self::ensureIndex('favorites', 'idx_favorites_user', 'user_id');
        self::ensureColumn('dvr_servers', 'last_metrics_at', 'TEXT');
        self::ensureColumn('dvr_servers', 'last_metrics_json', 'TEXT');
        self::ensureColumn('cameras', 'last_sync_at', 'TEXT');
        self::ensureColumn('cameras', 'last_sync_ok', 'INTEGER');
        self::ensureColumn('cameras', 'last_sync_message', 'TEXT');
        self::ensureColumn('cameras', 'archive_enabled', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn('cameras', 'dvr_retention_days', 'VARCHAR(64)');
        self::ensureColumn('cameras', 'dvr_archive_enabled', 'INTEGER');
        self::ensureColumn('cameras', 'webrtc_fast_start', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('cameras', 'event_archive_retention_enabled', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('cameras', 'event_archive_max_bytes', self::driver() === 'sqlite' ? 'INTEGER' : 'BIGINT');
        self::ensureColumn('cameras', 'event_archive_max_duration', self::driver() === 'mysql' ? 'VARCHAR(64)' : 'TEXT');
        self::ensureColumn('cameras', 'event_archive_max_age', self::driver() === 'mysql' ? 'VARCHAR(64)' : 'TEXT');
        self::ensureColumn('cameras', 'timelapse_enabled', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('cameras', 'timelapse_frames_per_hour', 'INTEGER NOT NULL DEFAULT 60');
        self::ensureColumn('cameras', 'timelapse_retention_days', self::driver() === 'mysql' ? 'VARCHAR(64)' : 'TEXT');
        self::ensureColumn('cameras', 'timelapse_playback_fps', 'INTEGER NOT NULL DEFAULT 25');
        self::ensureColumn('cameras', 'direct_archive_video_timeline_repair_mode', self::driver() === 'mysql' ? 'VARCHAR(16)' : 'TEXT');
        self::ensureColumn('cameras', 'audio_codec', self::driver() === 'mysql' ? "VARCHAR(16) NOT NULL DEFAULT 'copy'" : "TEXT NOT NULL DEFAULT 'copy'");
        self::ensureColumn('cameras', 'dvr_control_mode', self::driver() === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT 'managed'" : "TEXT NOT NULL DEFAULT 'managed'");
        self::ensureColumn('cameras', 'agent_id', self::driver() === 'mysql' ? 'VARCHAR(255)' : 'TEXT');
        self::ensureColumn('cameras', 'agent_camera_id', self::driver() === 'mysql' ? 'VARCHAR(255)' : 'TEXT');
        self::ensureColumn('cameras', 'onvif_events_requested', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('cameras', 'watermark_enabled', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('cameras', 'watermark_intensity', 'INTEGER NOT NULL DEFAULT 16');
        self::ensureIndex('cameras', 'idx_cameras_agent_id', 'agent_id');
        self::ensureIndex('cameras', 'idx_cameras_agent_camera_id', 'agent_camera_id');
    }

    public static function insertIgnoreSql(string $table, array $columns): string
    {
        $columnSql = implode(', ', $columns);
        $placeholderSql = implode(', ', array_fill(0, count($columns), '?'));
        return match (self::driver()) {
            'pgsql' => "INSERT INTO {$table}({$columnSql}) VALUES({$placeholderSql}) ON CONFLICT DO NOTHING",
            'mysql' => "INSERT IGNORE INTO {$table}({$columnSql}) VALUES({$placeholderSql})",
            default => "INSERT OR IGNORE INTO {$table}({$columnSql}) VALUES({$placeholderSql})",
        };
    }

    public static function randomOrderSql(): string
    {
        return self::driver() === 'mysql' ? 'RAND()' : 'RANDOM()';
    }

    public static function caseInsensitiveLike(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => $column . ' ILIKE ?',
            'sqlite' => 'sesame_portal_lower(COALESCE(' . $column . ", '')) LIKE sesame_portal_lower(?)",
            default => 'LOWER(COALESCE(' . $column . ", '')) LIKE LOWER(?)",
        };
    }

    public static function lastInsertId(string $table): int
    {
        if (self::driver() === 'pgsql') {
            $stmt = self::pdo()->prepare("SELECT currval(pg_get_serial_sequence(?, 'id'))");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn();
        }
        return (int)self::pdo()->lastInsertId();
    }

    public static function setForeignKeys(bool $enabled): void
    {
        if (self::driver() === 'sqlite') {
            self::pdo()->exec('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'));
        } elseif (self::driver() === 'mysql') {
            self::pdo()->exec('SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0'));
        }
    }

    private static function schemaStatements(): array
    {
        return match (self::driver()) {
            'pgsql' => self::pgsqlSchema(),
            'mysql' => self::mysqlSchema(),
            default => self::sqliteSchema(),
        };
    }

    private static function sqliteSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user",
                blocked INTEGER NOT NULL DEFAULT 0,
                daily_token TEXT,
                previous_daily_token TEXT,
                daily_token_date TEXT,
                static_token_hash TEXT,
                admin_comment TEXT,
                hide_archive INTEGER NOT NULL DEFAULT 0,
                ptz_allowed INTEGER NOT NULL DEFAULT 0,
                mosaic_columns INTEGER NOT NULL DEFAULT 3,
                mosaic_preview_refresh VARCHAR(16),
                created_at TEXT NOT NULL,
                last_login_at TEXT
            )',
            'CREATE TABLE IF NOT EXISTS portal_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_group_id INTEGER REFERENCES portal_groups(id) ON DELETE SET NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS user_groups (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                group_id INTEGER NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS dvr_servers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                base_url TEXT NOT NULL,
                management_token_enc TEXT,
                blocked INTEGER NOT NULL DEFAULT 0,
                last_check_at TEXT,
                last_check_result TEXT,
                last_metrics_at TEXT,
                last_metrics_json TEXT,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS cameras (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                source_url TEXT NOT NULL,
                server_id INTEGER REFERENCES dvr_servers(id) ON DELETE SET NULL,
                server_selection TEXT NOT NULL DEFAULT "manual",
                latitude REAL,
                longitude REAL,
                direction_deg INTEGER NOT NULL DEFAULT 0,
                view_angle_deg INTEGER NOT NULL DEFAULT 60,
                retention_days TEXT NOT NULL DEFAULT "7d",
                archive_enabled INTEGER NOT NULL DEFAULT 1,
                webrtc_fast_start INTEGER NOT NULL DEFAULT 0,
                event_archive_retention_enabled INTEGER NOT NULL DEFAULT 0,
                event_archive_max_bytes INTEGER,
                event_archive_max_duration TEXT,
                event_archive_max_age TEXT,
                timelapse_enabled INTEGER NOT NULL DEFAULT 0,
                timelapse_frames_per_hour INTEGER NOT NULL DEFAULT 60,
                timelapse_retention_days TEXT,
                timelapse_playback_fps INTEGER NOT NULL DEFAULT 25,
                direct_archive_video_timeline_repair_mode TEXT,
                audio_codec TEXT NOT NULL DEFAULT "copy",
                dvr_control_mode TEXT NOT NULL DEFAULT "managed",
                agent_id TEXT,
                agent_camera_id TEXT,
                onvif_events_requested INTEGER NOT NULL DEFAULT 0,
                watermark_enabled INTEGER NOT NULL DEFAULT 0,
                watermark_intensity INTEGER NOT NULL DEFAULT 16,
                blocked INTEGER NOT NULL DEFAULT 0,
                dvr_stream_name TEXT NOT NULL,
                last_sync_at TEXT,
                last_sync_ok INTEGER,
                last_sync_message TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS camera_groups (
                camera_id INTEGER NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                group_id INTEGER NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (camera_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS favorites (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                camera_id INTEGER NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, camera_id)
            )',
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id INTEGER,
                action TEXT NOT NULL,
                details TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS portal_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        ];
    }

    private static function pgsqlSchema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                login TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                blocked INTEGER NOT NULL DEFAULT 0,
                daily_token TEXT,
                previous_daily_token TEXT,
                daily_token_date TEXT,
                static_token_hash TEXT,
                admin_comment TEXT,
                hide_archive INTEGER NOT NULL DEFAULT 0,
                ptz_allowed INTEGER NOT NULL DEFAULT 0,
                mosaic_columns INTEGER NOT NULL DEFAULT 3,
                mosaic_preview_refresh VARCHAR(16),
                created_at TEXT NOT NULL,
                last_login_at TEXT
            )",
            'CREATE TABLE IF NOT EXISTS portal_groups (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                parent_group_id BIGINT REFERENCES portal_groups(id) ON DELETE SET NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL,
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS user_groups (
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                group_id BIGINT NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS dvr_servers (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL,
                base_url TEXT NOT NULL,
                management_token_enc TEXT,
                blocked INTEGER NOT NULL DEFAULT 0,
                last_check_at TEXT,
                last_check_result TEXT,
                last_metrics_at TEXT,
                last_metrics_json TEXT,
                created_at TEXT NOT NULL
            )',
            "CREATE TABLE IF NOT EXISTS cameras (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                source_url TEXT NOT NULL,
                server_id BIGINT REFERENCES dvr_servers(id) ON DELETE SET NULL,
                server_selection TEXT NOT NULL DEFAULT 'manual',
                latitude DOUBLE PRECISION,
                longitude DOUBLE PRECISION,
                direction_deg INTEGER NOT NULL DEFAULT 0,
                view_angle_deg INTEGER NOT NULL DEFAULT 60,
                retention_days TEXT NOT NULL DEFAULT '7d',
                archive_enabled INTEGER NOT NULL DEFAULT 1,
                webrtc_fast_start INTEGER NOT NULL DEFAULT 0,
                event_archive_retention_enabled INTEGER NOT NULL DEFAULT 0,
                event_archive_max_bytes BIGINT,
                event_archive_max_duration TEXT,
                event_archive_max_age TEXT,
                timelapse_enabled INTEGER NOT NULL DEFAULT 0,
                timelapse_frames_per_hour INTEGER NOT NULL DEFAULT 60,
                timelapse_retention_days TEXT,
                timelapse_playback_fps INTEGER NOT NULL DEFAULT 25,
                direct_archive_video_timeline_repair_mode TEXT,
                audio_codec TEXT NOT NULL DEFAULT 'copy',
                dvr_control_mode TEXT NOT NULL DEFAULT 'managed',
                agent_id TEXT,
                agent_camera_id TEXT,
                onvif_events_requested INTEGER NOT NULL DEFAULT 0,
                watermark_enabled INTEGER NOT NULL DEFAULT 0,
                watermark_intensity INTEGER NOT NULL DEFAULT 16,
                blocked INTEGER NOT NULL DEFAULT 0,
                dvr_stream_name TEXT NOT NULL,
                last_sync_at TEXT,
                last_sync_ok INTEGER,
                last_sync_message TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            'CREATE TABLE IF NOT EXISTS camera_groups (
                camera_id BIGINT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                group_id BIGINT NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (camera_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS favorites (
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                camera_id BIGINT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, camera_id)
            )',
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                actor_user_id BIGINT,
                action TEXT NOT NULL,
                details TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS portal_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        ];
    }

    private static function mysqlSchema(): array
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                login VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(32) NOT NULL DEFAULT 'user',
                blocked INTEGER NOT NULL DEFAULT 0,
                daily_token TEXT,
                previous_daily_token TEXT,
                daily_token_date VARCHAR(64),
                static_token_hash VARCHAR(255),
                admin_comment TEXT,
                hide_archive INTEGER NOT NULL DEFAULT 0,
                ptz_allowed INTEGER NOT NULL DEFAULT 0,
                mosaic_columns INTEGER NOT NULL DEFAULT 3,
                mosaic_preview_refresh VARCHAR(16),
                created_at VARCHAR(64) NOT NULL,
                last_login_at VARCHAR(64)
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS portal_groups (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                parent_group_id BIGINT,
                name VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at VARCHAR(64) NOT NULL,
                CONSTRAINT fk_portal_groups_parent FOREIGN KEY (parent_group_id) REFERENCES portal_groups(id) ON DELETE SET NULL
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS user_groups (
                user_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                PRIMARY KEY (user_id, group_id),
                CONSTRAINT fk_user_groups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_groups_group FOREIGN KEY (group_id) REFERENCES portal_groups(id) ON DELETE CASCADE
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS dvr_servers (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                base_url TEXT NOT NULL,
                management_token_enc TEXT,
                blocked INTEGER NOT NULL DEFAULT 0,
                last_check_at VARCHAR(64),
                last_check_result TEXT,
                last_metrics_at VARCHAR(64),
                last_metrics_json MEDIUMTEXT,
                created_at VARCHAR(64) NOT NULL
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS cameras (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                source_url TEXT NOT NULL,
                server_id BIGINT,
                server_selection VARCHAR(32) NOT NULL DEFAULT 'manual',
                latitude DOUBLE,
                longitude DOUBLE,
                direction_deg INTEGER NOT NULL DEFAULT 0,
                view_angle_deg INTEGER NOT NULL DEFAULT 60,
                retention_days VARCHAR(64) NOT NULL DEFAULT '7d',
                archive_enabled INTEGER NOT NULL DEFAULT 1,
                webrtc_fast_start INTEGER NOT NULL DEFAULT 0,
                event_archive_retention_enabled INTEGER NOT NULL DEFAULT 0,
                event_archive_max_bytes BIGINT,
                event_archive_max_duration VARCHAR(64),
                event_archive_max_age VARCHAR(64),
                timelapse_enabled INTEGER NOT NULL DEFAULT 0,
                timelapse_frames_per_hour INTEGER NOT NULL DEFAULT 60,
                timelapse_retention_days VARCHAR(64),
                timelapse_playback_fps INTEGER NOT NULL DEFAULT 25,
                direct_archive_video_timeline_repair_mode VARCHAR(16),
                audio_codec VARCHAR(16) NOT NULL DEFAULT 'copy',
                dvr_control_mode VARCHAR(32) NOT NULL DEFAULT 'managed',
                agent_id VARCHAR(255),
                agent_camera_id VARCHAR(255),
                onvif_events_requested INTEGER NOT NULL DEFAULT 0,
                watermark_enabled INTEGER NOT NULL DEFAULT 0,
                watermark_intensity INTEGER NOT NULL DEFAULT 16,
                blocked INTEGER NOT NULL DEFAULT 0,
                dvr_stream_name VARCHAR(255) NOT NULL,
                last_sync_at VARCHAR(64),
                last_sync_ok INTEGER,
                last_sync_message TEXT,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL,
                CONSTRAINT fk_cameras_server FOREIGN KEY (server_id) REFERENCES dvr_servers(id) ON DELETE SET NULL
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS camera_groups (
                camera_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                PRIMARY KEY (camera_id, group_id),
                CONSTRAINT fk_camera_groups_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE,
                CONSTRAINT fk_camera_groups_group FOREIGN KEY (group_id) REFERENCES portal_groups(id) ON DELETE CASCADE
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS favorites (
                user_id BIGINT NOT NULL,
                camera_id BIGINT NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                PRIMARY KEY (user_id, camera_id),
                CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_favorites_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                actor_user_id BIGINT,
                action VARCHAR(255) NOT NULL,
                details TEXT NOT NULL,
                created_at VARCHAR(64) NOT NULL
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS portal_settings (
                setting_key VARCHAR(191) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            ){$suffix}",
        ];
    }

    private static function dropPortalGroupNameUniqueConstraint(): void
    {
        match (self::driver()) {
            'pgsql' => self::dropPgsqlPortalGroupNameUniqueConstraint(),
            'mysql' => self::dropMysqlPortalGroupNameUniqueConstraint(),
            default => self::dropSqlitePortalGroupNameUniqueConstraint(),
        };
    }

    private static function dropSqlitePortalGroupNameUniqueConstraint(): void
    {
        $pdo = self::pdo();
        if (!self::sqliteUniqueIndexOnColumns('portal_groups', ['name'])) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $pdo->beginTransaction();
            $pdo->exec('DROP TABLE IF EXISTS portal_groups_migration');
            $pdo->exec('CREATE TABLE portal_groups_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_group_id INTEGER REFERENCES portal_groups(id) ON DELETE SET NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )');
            $pdo->exec('INSERT INTO portal_groups_migration(id, parent_group_id, name, description, blocked, created_at)
                SELECT id, parent_group_id, name, description, blocked, created_at FROM portal_groups');
            $pdo->exec('DROP TABLE portal_groups');
            $pdo->exec('ALTER TABLE portal_groups_migration RENAME TO portal_groups');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private static function sqliteUniqueIndexOnColumns(string $table, array $columns): bool
    {
        $pdo = self::pdo();
        $indexes = $pdo->query('PRAGMA index_list(' . self::quoteIdentifier($table) . ')')->fetchAll();
        foreach ($indexes as $index) {
            if ((int)($index['unique'] ?? 0) !== 1) {
                continue;
            }

            $indexName = (string)($index['name'] ?? '');
            if ($indexName === '') {
                continue;
            }

            $info = $pdo->query('PRAGMA index_info(' . self::quoteIdentifier($indexName) . ')')->fetchAll();
            $indexColumns = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $info);
            if ($indexColumns === $columns) {
                return true;
            }
        }
        return false;
    }

    private static function dropPgsqlPortalGroupNameUniqueConstraint(): void
    {
        $pdo = self::pdo();
        $constraints = $pdo->query(
            "SELECT c.conname, string_agg(a.attname, ',' ORDER BY u.ord) AS columns
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN unnest(c.conkey) WITH ORDINALITY AS u(attnum, ord) ON true
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = u.attnum
             WHERE n.nspname = current_schema()
               AND t.relname = 'portal_groups'
               AND c.contype = 'u'
             GROUP BY c.conname"
        )->fetchAll();

        foreach ($constraints as $constraint) {
            if (($constraint['columns'] ?? '') === 'name') {
                $pdo->exec('ALTER TABLE portal_groups DROP CONSTRAINT ' . self::quoteIdentifier((string)$constraint['conname']));
            }
        }

        $indexes = $pdo->query(
            "SELECT i.relname AS index_name, string_agg(a.attname, ',' ORDER BY u.ord) AS columns
             FROM pg_index ix
             JOIN pg_class t ON t.oid = ix.indrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN unnest(ix.indkey) WITH ORDINALITY AS u(attnum, ord) ON true
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = u.attnum
             WHERE n.nspname = current_schema()
               AND t.relname = 'portal_groups'
               AND ix.indisunique
               AND NOT ix.indisprimary
             GROUP BY i.relname"
        )->fetchAll();

        foreach ($indexes as $index) {
            if (($index['columns'] ?? '') === 'name') {
                $pdo->exec('DROP INDEX IF EXISTS ' . self::quoteIdentifier((string)$index['index_name']));
            }
        }
    }

    private static function dropMysqlPortalGroupNameUniqueConstraint(): void
    {
        $stmt = self::pdo()->prepare(
            "SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'portal_groups'
               AND non_unique = 0
               AND index_name <> 'PRIMARY'
             GROUP BY index_name"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll() as $index) {
            if (($index['columns'] ?? '') === 'name') {
                self::pdo()->exec('ALTER TABLE portal_groups DROP INDEX ' . self::quoteIdentifier((string)$index['index_name']));
            }
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new RuntimeException('Invalid database identifier');
        }

        $quote = self::driver() === 'mysql' ? '`' : '"';
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        $pdo = self::pdo();
        if (self::columnExists($table, $column)) {
            return;
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function ensureIndex(string $table, string $index, string $column): void
    {
        if (self::indexExists($table, $index)) {
            return;
        }
        self::pdo()->exec("CREATE INDEX {$index} ON {$table}({$column})");
    }

    private static function columnExists(string $table, string $column): bool
    {
        $pdo = self::pdo();
        if (self::driver() === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
            foreach ($columns as $existing) {
                if (($existing['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        if (self::driver() === 'pgsql') {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    private static function indexExists(string $table, string $index): bool
    {
        $pdo = self::pdo();
        if (self::driver() === 'sqlite') {
            $indexes = $pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll();
            foreach ($indexes as $existing) {
                if (($existing['name'] ?? '') === $index) {
                    return true;
                }
            }
            return false;
        }

        if (self::driver() === 'pgsql') {
            $stmt = $pdo->prepare('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?');
            $stmt->execute([$table, $index]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    }
}
