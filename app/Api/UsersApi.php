<?php

declare(strict_types=1);

namespace SesamePortal;

trait UsersApi
{
    private static function apiUsers(array $parts): void
    {
        $actor = self::apiRequireAdmin();
        $method = self::apiMethod();
        $id = isset($parts[1]) ? (int)$parts[1] : 0;

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $list = self::filteredUsers(self::apiPageSize());
                self::apiJson([
                    'users' => array_map(static fn(array $row): array => self::apiUserRow($row, false, true), $list['rows']),
                    'pagination' => self::apiPagination($list),
                ]);
                return;
            }
            if ($method === 'POST') {
                self::apiSaveUser(0, self::apiInput(), $actor);
                return;
            }
        }

        if ($id <= 0) {
            self::apiError(404, 'not_found', 'User not found');
            return;
        }

        if (count($parts) === 2) {
            if ($method === 'GET') {
                $user = self::rowById('users', $id);
                $user ? self::apiJson(['user' => self::apiUserRow($user, true, true)]) : self::apiError(404, 'not_found', 'User not found');
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveUser($id, self::apiInput(), $actor);
                return;
            }
            if ($method === 'DELETE') {
                DB::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                Audit::log('user.delete', 'user_id=' . $id);
                self::apiJson(['ok' => true]);
                return;
            }
        }

        if (($parts[2] ?? '') === 'static-token') {
            if (!self::rowById('users', $id)) {
                self::apiError(404, 'not_found', 'User not found');
                return;
            }
            if ($method === 'POST') {
                self::apiJson(['token' => TokenService::issueStaticToken($id, $actor)]);
                return;
            }
            if ($method === 'DELETE') {
                TokenService::revokeStaticToken($id, $actor);
                self::apiJson(['ok' => true]);
                return;
            }
        }

        self::apiError(404, 'not_found', 'Unknown users endpoint');
    }

    private static function apiSaveUser(int $id, array $input, ?array $actor = null): void
    {
        $current = $id > 0 ? self::rowById('users', $id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'User not found');
            return;
        }

        $login = trim((string)($input['login'] ?? ($current['login'] ?? '')));
        $password = (string)($input['password'] ?? '');
        $role = ($input['role'] ?? ($current['role'] ?? 'user')) === 'admin' ? 'admin' : 'user';
        $blocked = self::apiBlockedValue($input, $current);
        $hideArchive =
            array_key_exists('hideArchive', $input) || array_key_exists('hide_archive', $input)
                ? (self::apiBool($input['hideArchive'] ?? $input['hide_archive']) ? 1 : 0)
                : (int)($current['hide_archive'] ?? 0);
        $adminComment = trim((string)($input['adminComment'] ?? $input['admin_comment'] ?? ($current['admin_comment'] ?? '')));
        $ptzAllowed =
            array_key_exists('ptzAllowed', $input) || array_key_exists('ptz_allowed', $input)
                ? (self::apiBool($input['ptzAllowed'] ?? $input['ptz_allowed'] ?? false) ? 1 : 0)
                : (int)($current['ptz_allowed'] ?? 0);
        if ($login === '') {
            self::apiError(422, 'validation_failed', 'login is required');
            return;
        }
        if ($id === 0 && strlen($password) < 6) {
            self::apiError(422, 'validation_failed', 'password must be at least 6 characters');
            return;
        }
        $existing = self::userByLogin($login);
        if ($existing && (int)$existing['id'] !== $id) {
            self::apiUserLoginExists($existing);
            return;
        }
        if (array_key_exists('groupIds', $input) || array_key_exists('group_ids', $input)) {
            $groupIds = self::apiIntArray($input['groupIds'] ?? $input['group_ids'] ?? []);
            self::apiValidateExistingIds('groupIds', 'portal_groups', $groupIds);
        } else {
            $groupIds = null;
        }

        $beforeGroupIds = $id > 0 ? self::linkedIds('user_groups', 'user_id', $id, 'group_id') : [];
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                if ($password !== '') {
                    if (strlen($password) < 6) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        self::apiError(422, 'validation_failed', 'password must be at least 6 characters');
                        return;
                    }
                    $pdo->prepare('UPDATE users SET login=?, password_hash=?, role=?, blocked=?, hide_archive=?, ptz_allowed=?, admin_comment=? WHERE id=?')
                        ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $ptzAllowed, $adminComment, $id]);
                } else {
                    $pdo->prepare('UPDATE users SET login=?, role=?, blocked=?, hide_archive=?, ptz_allowed=?, admin_comment=? WHERE id=?')
                        ->execute([$login, $role, $blocked, $hideArchive, $ptzAllowed, $adminComment, $id]);
                }
            } else {
                $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, hide_archive, ptz_allowed, admin_comment, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $ptzAllowed, $adminComment, Util::randomToken(), TokenService::today(), Util::now()]);
                $id = DB::lastInsertId('users');
            }
            if ($blocked || $password !== '') {
                Auth::revokeRememberedLogins($id);
            }
            if ($groupIds !== null) {
                self::replaceLinks('user_groups', 'user_id', $id, 'group_id', $groupIds);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof \PDOException && self::isUserLoginUniqueConstraint($error)) {
                self::apiUserLoginExists(self::userByLogin($login));
                return;
            }
            throw $error;
        }
        $after = self::rowById('users', $id) ?: ['login' => $login, 'role' => $role, 'blocked' => $blocked, 'hide_archive' => $hideArchive, 'ptz_allowed' => $ptzAllowed];
        $afterGroupIds = self::linkedIds('user_groups', 'user_id', $id, 'group_id');
        self::logUserSaveAudit($actor, $id, $current, $after, $beforeGroupIds, $afterGroupIds);
        self::apiJson(['user' => self::apiUserRow($after, true, true)], $current ? 200 : 201);
    }

    private static function apiUserLoginExists(?array $existing): void
    {
        self::apiError(409, 'login_exists', 'login already exists', [
            'existingId' => (int)($existing['id'] ?? 0),
        ]);
    }

    private static function isUserLoginUniqueConstraint(\PDOException $error): bool
    {
        $code = (string)$error->getCode();
        $message = strtolower($error->getMessage());
        return in_array($code, ['23000', '23505'], true)
            && (str_contains($message, 'users.login')
                || str_contains($message, 'users_login')
                || str_contains($message, 'for key \'login\'')
                || str_contains($message, 'for key "login"'));
    }

    private static function logUserSaveAudit(?array $actor, int $userId, ?array $before, array $after, array $beforeGroupIds, array $afterGroupIds): void
    {
        $details = self::userSaveAuditDetails($userId, $before, $after, $beforeGroupIds, $afterGroupIds);
        $actorId = $actor['id'] ?? null;
        if ($actorId !== null) {
            Audit::logForUser($actorId, 'user.save', $details);
            return;
        }
        Audit::log('user.save', $details);
    }

    private static function userSaveAuditDetails(int $userId, ?array $before, array $after, array $beforeGroupIds, array $afterGroupIds): string
    {
        return implode(' ', [
            'user_id=' . $userId,
            'login=' . self::auditFieldTransition($before, $after, 'login'),
            'role=' . self::auditFieldTransition($before, $after, 'role'),
            'blocked=' . self::auditFieldTransition($before, $after, 'blocked', true),
            'hide_archive=' . self::auditFieldTransition($before, $after, 'hide_archive', true),
            'ptz_allowed=' . self::auditFieldTransition($before, $after, 'ptz_allowed', true),
            'groups=' . self::auditIdList($beforeGroupIds) . '->' . self::auditIdList($afterGroupIds),
            'ip=' . Audit::clientIp(),
        ]);
    }

    private static function auditFieldTransition(?array $before, array $after, string $key, bool $intValue = false): string
    {
        $old = $before ? ($before[$key] ?? '') : 'new';
        $new = $after[$key] ?? '';
        if ($intValue && $before) {
            $old = (int)$old;
        }
        if ($intValue) {
            $new = (int)$new;
        }
        return Audit::cleanValue((string)$old, 80) . '->' . Audit::cleanValue((string)$new, 80);
    }

    private static function auditIdList(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return '[' . implode(',', $ids) . ']';
    }

    private static function apiUserRow(?array $user, bool $detailed = false, bool $includeAdminComment = false): array
    {
        if (!$user) {
            return [];
        }
        $row = [
            'id' => (int)$user['id'],
            'login' => (string)$user['login'],
            'role' => (string)$user['role'],
            'blocked' => (int)($user['blocked'] ?? 0) === 1,
            'hideArchive' => (int)($user['hide_archive'] ?? 0) === 1,
            'ptzAllowed' => (int)($user['ptz_allowed'] ?? 0) === 1,
            'hasStaticToken' => !empty($user['static_token_hash']),
            'createdAt' => $user['created_at'] ?? null,
            'lastLoginAt' => $user['last_login_at'] ?? null,
        ];
        if ($includeAdminComment) {
            $row['adminComment'] = (string)($user['admin_comment'] ?? '');
        }
        if ($detailed) {
            $row['groupIds'] = self::linkedIds('user_groups', 'user_id', (int)$user['id'], 'group_id');
        }
        return $row;
    }
}
