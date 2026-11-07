<?php

declare(strict_types=1);

namespace SesamePortal;

use RuntimeException;

final class Cli
{
    private const BACKUP_TABLES = [
        'users',
        'portal_groups',
        'user_groups',
        'dvr_servers',
        'cameras',
        'camera_groups',
        'favorites',
        'video_walls',
        'audit_logs',
        'portal_settings',
        'rbt_authbackend_secrets',
    ];

    public static function run(array $argv): void
    {
        DB::migrate();
        $command = $argv[1] ?? 'help';

        if ($command === 'migrate') {
            echo "migrated\n";
            return;
        }

        if ($command === 'create-admin') {
            $login = $argv[2] ?? '';
            $password = $argv[3] ?? '';
            if ($login === '' || strlen($password) < 6) {
                fwrite(STDERR, "usage: php bin/portal create-admin <login> <password-min-6>\n");
                exit(2);
            }
            $pdo = DB::pdo();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE login = ?');
            $stmt->execute([$login]);
            if ($stmt->fetch()) {
                $pdo->prepare('UPDATE users SET password_hash=?, role=?, blocked=0 WHERE login=?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), 'admin', $login]);
            } else {
                $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, 0, ?, ?, ?)')
                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), 'admin', Util::randomToken(), TokenService::today(), Util::now()]);
            }
            echo "admin ready: {$login}\n";
            return;
        }

        if ($command === 'rotate-tokens') {
            $count = TokenService::rotateAll();
            echo "rotated {$count} users\n";
            // The existing daily cron also cleans up expired RBT signing secrets.
            $removed = RbtAuthBackend::cleanup();
            echo "removed {$removed} expired RBT secrets\n";
            return;
        }

        if ($command === 'rotate-secrets') {
            $count = self::rotateSecrets();
            echo "rotated {$count} encrypted secrets\n";
            return;
        }

        if ($command === 'backup') {
            $path = $argv[2] ?? '';
            if ($path === '') {
                fwrite(STDERR, "usage: php bin/portal backup <out.json>\n");
                exit(2);
            }
            self::backup($path);
            echo "backup written: {$path}\n";
            return;
        }

        if ($command === 'restore') {
            $path = $argv[2] ?? '';
            if ($path === '' || !is_file($path)) {
                fwrite(STDERR, "usage: php bin/portal restore <in.json>\n");
                exit(2);
            }
            self::restore($path);
            echo "backup restored: {$path}\n";
            return;
        }

        echo "commands: migrate, create-admin, rotate-tokens, rotate-secrets, backup, restore\n";
    }

    private static function rotateSecrets(): int
    {
        $pdo = DB::pdo();
        $rows = $pdo->query('SELECT id, management_token_enc FROM dvr_servers WHERE management_token_enc IS NOT NULL AND management_token_enc != ""')->fetchAll();
        $stmt = $pdo->prepare('UPDATE dvr_servers SET management_token_enc = ? WHERE id = ?');
        $count = 0;
        foreach ($rows as $row) {
            $encoded = (string)$row['management_token_enc'];
            if (!Crypto::needsRotation($encoded)) {
                continue;
            }

            $plain = Crypto::decrypt($encoded);
            if ($plain === '') {
                continue;
            }

            $stmt->execute([Crypto::encrypt($plain), (int)$row['id']]);
            $count++;
        }

        return $count;
    }

    private static function backup(string $path): void
    {
        $pdo = DB::pdo();
        $data = [
            'format' => 'sesame-portal-backup-v1',
            'createdAt' => Util::now(),
            'tables' => [],
        ];
        foreach (self::BACKUP_TABLES as $table) {
            $data['tables'][$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
        }

        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);
    }

    private static function restore(string $path): void
    {
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'sesame-portal-backup-v1') {
            throw new RuntimeException('invalid_backup_format');
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::setForeignKeys(false);
            // Device credentials are not backup data. Never revive saved logins
            // when user IDs/password hashes are restored from an older snapshot.
            $pdo->exec('DELETE FROM remembered_logins');
            foreach (array_reverse(self::BACKUP_TABLES) as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }
            foreach (self::BACKUP_TABLES as $table) {
                foreach (($data['tables'][$table] ?? []) as $row) {
                    if (!is_array($row) || $row === []) {
                        continue;
                    }
                    $columns = array_keys($row);
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $sql = 'INSERT INTO ' . $table . '(' . implode(', ', $columns) . ') VALUES(' . $placeholders . ')';
                    $pdo->prepare($sql)->execute(array_values($row));
                }
            }
            if (DB::driver() === 'pgsql') {
                $pdo->query("SELECT setval(pg_get_serial_sequence('video_walls', 'id'), COALESCE(MAX(id), 1), COUNT(*) > 0) FROM video_walls");
            }
            DB::setForeignKeys(true);
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            DB::setForeignKeys(true);
            throw $error;
        }
    }
}
