<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;

trait UsersPages
{
    private static function users(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        $messageClass = '';
        $staticToken = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);

            if ($action === 'save') {
                $login = trim((string)Util::post('login'));
                $password = (string)Util::post('password');
                $role = Util::post('role') === 'admin' ? 'admin' : 'user';
                $blocked = Util::checkbox('blocked');
                $hideArchive = Util::checkbox('hide_archive');
                $ptzAllowed = Util::checkbox('ptz_allowed');
                $adminComment = trim((string)Util::post('admin_comment'));
                $beforeUser = $id > 0 ? self::rowById('users', $id) : null;
                $beforeGroupIds = $id > 0 ? self::linkedIds('user_groups', 'user_id', $id, 'group_id') : [];
                $groupIds = self::formIntArray('group_ids_json', 'group_ids');
                $missingGroupIds = self::missingIds('portal_groups', $groupIds);
                if ($missingGroupIds !== []) {
                    $message = 'Selected groups contain unknown id(s): ' . implode(', ', $missingGroupIds);
                } elseif ($login === '') {
                    $message = self::t('users.loginRequired', 'Логин обязателен');
                } elseif ($id === 0 && strlen($password) < 6) {
                    $message = self::t('users.passwordShort', 'Пароль должен быть не короче 6 символов');
                } else {
                    if ($id > 0) {
                        if ($password !== '') {
                            if (strlen($password) < 6) {
                                $message = self::t('users.passwordShort', 'Пароль должен быть не короче 6 символов');
                            } else {
                                $pdo->prepare('UPDATE users SET login=?, password_hash=?, role=?, blocked=?, hide_archive=?, ptz_allowed=?, admin_comment=? WHERE id=?')
                                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $ptzAllowed, $adminComment, $id]);
                            }
                        } else {
                            $pdo->prepare('UPDATE users SET login=?, role=?, blocked=?, hide_archive=?, ptz_allowed=?, admin_comment=? WHERE id=?')
                                ->execute([$login, $role, $blocked, $hideArchive, $ptzAllowed, $adminComment, $id]);
                        }
                    } else {
                        $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, hide_archive, ptz_allowed, admin_comment, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $ptzAllowed, $adminComment, Util::randomToken(), TokenService::today(), Util::now()]);
                        $id = DB::lastInsertId('users');
                    }
                    if ($message === '') {
                        if ($blocked || $password !== '') {
                            Auth::revokeRememberedLogins($id);
                        }
                        self::replaceLinks('user_groups', 'user_id', $id, 'group_id', $groupIds);
                        $afterUser = self::rowById('users', $id) ?: ['login' => $login, 'role' => $role, 'blocked' => $blocked, 'hide_archive' => $hideArchive, 'ptz_allowed' => $ptzAllowed];
                        $afterGroupIds = self::linkedIds('user_groups', 'user_id', $id, 'group_id');
                        self::logUserSaveAudit(null, $id, $beforeUser, $afterUser, $beforeGroupIds, $afterGroupIds);
                        $message = self::t('users.saveDone', 'Пользователь сохранён');
                        $messageClass = 'success';
                    }
                }
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                Audit::log('user.delete', 'user_id=' . $id);
            } elseif ($action === 'issue_static' && $id > 0) {
                $staticToken = TokenService::issueStaticToken($id);
            } elseif ($action === 'revoke_static' && $id > 0) {
                TokenService::revokeStaticToken($id);
            }
        }

        $edit = self::rowById('users', (int)($_GET['edit'] ?? 0));
        $linkedGroups = $edit ? self::linkedIds('user_groups', 'user_id', (int)$edit['id'], 'group_id') : [];
        $groups = self::groupRowsWithDisplayLabels(Repo::all('portal_groups', 'name ASC'));
        $list = self::filteredUsers();
        $users = $list['rows'];
        self::layout(self::t('users.title', 'Пользователи'), function () use ($users, $edit, $groups, $linkedGroups, $message, $messageClass, $staticToken, $list) {
            self::notice($message, $messageClass);
            if ($staticToken) {
                echo '<div class="alert"><strong>' . self::t('token.staticIssued', 'Новый static token. Сохраните его сейчас: позже Portal покажет только наличие token') . '</strong><br><code>' . Util::h($staticToken) . '</code></div>';
            }
            echo '<div class="admin-grid">';
            echo '<section class="panel"><h2>' . ($edit ? self::t('users.edit', 'Изменить пользователя') : self::t('users.new', 'Новый пользователь')) . '</h2>';
            $savingLabel = self::t('users.saving', 'Сохраняем пользователя...');
            echo '<form method="post" class="form" data-submit-progress="' . Util::h($savingLabel) . '">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" value="' . Util::h($edit['login'] ?? '') . '" required></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" minlength="6" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : self::t('users.passwordPlaceholderNew', 'минимум 6 символов')) . '"></label>';
            echo '<label>' . self::t('column.role', 'Роль') . '<select name="role"><option value="user">user</option><option value="admin" ' . (($edit['role'] ?? '') === 'admin' ? 'selected' : '') . '>admin</option></select></label>';
            echo '<label>' . self::t('users.adminComment', 'Комментарий администратора') . '<textarea name="admin_comment" rows="3">' . Util::h($edit['admin_comment'] ?? '') . '</textarea></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('users.blocked', 'Заблокирован') . '</label>';
            echo '<label class="check"><input type="checkbox" name="hide_archive" ' . (!empty($edit['hide_archive']) ? 'checked' : '') . '> ' . self::t('users.hideArchive', 'Скрывать архив') . '</label>';
            echo '<label class="check"><input type="checkbox" name="ptz_allowed" ' . (!empty($edit['ptz_allowed']) ? 'checked' : '') . '> ' . self::t('users.ptzAllowed', 'Разрешить PTZ') . '</label>';
            self::groupCheckboxTree(self::t('groups.title', 'Группы'), 'group_ids[]', $groups, $linkedGroups, 'group_ids_json');
            echo '<div class="form-submit-row"><button type="submit" class="primary" data-submit-button>' . self::t('action.save', 'Сохранить') . '</button><div class="submit-progress" data-submit-status hidden role="status" aria-live="polite">' . Util::h($savingLabel) . '</div></div></form></section>';
            self::table(self::t('users.title', 'Пользователи'), ['login', 'role', 'admin_comment', 'blocked', 'hide_archive', 'static_token_hash', 'last_login_at'], $users, '/admin/users', false, $list);
            echo '</div>';
        });
    }

    private static function filteredUsers(int $pageSize = 25): array
    {
        $filters = self::userListFilters();
        $page = $filters['page'];
        $where = [];
        $params = [];
        $join = '';

        if ($filters['q'] !== '') {
            $columns = ['u.login', 'u.role', 'u.admin_comment'];
            $where[] = '(' . implode(' OR ', array_map([DB::class, 'caseInsensitiveLike'], $columns)) . ')';
            array_push($params, ...array_fill(0, count($columns), '%' . $filters['q'] . '%'));
        }

        if ($filters['group_id'] > 0) {
            $join .= ' JOIN user_groups ug_filter ON ug_filter.user_id = u.id';
            $groupIds = Repo::groupBranchIds([$filters['group_id']], true, true);
            if (!$groupIds) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'ug_filter.group_id IN (' . self::sqlPlaceholders($groupIds) . ')';
                array_push($params, ...$groupIds);
            }
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(DISTINCT u.id) FROM users u' . $join . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT DISTINCT u.* FROM users u' . $join . $sqlWhere . ' ORDER BY u.login ASC LIMIT ? OFFSET ?');
        $bind = [...$params, $pageSize, ($page - 1) * $pageSize];
        foreach ($bind as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            ...$filters,
        ];
    }

    private static function userListFilters(): array
    {
        return [
            'q' => trim((string)($_GET['q'] ?? '')),
            'page' => max(1, (int)($_GET['page'] ?? 1)),
            'group_id' => max(0, (int)($_GET['group_id'] ?? $_GET['groupId'] ?? $_GET['groupID'] ?? 0)),
        ];
    }

    private static function userTableFilters(array $pager): void
    {
        $groups = self::groupRowsWithDisplayLabels(Repo::all('portal_groups', 'name ASC'));

        echo '<form method="get" action="/admin/users" class="table-search user-admin-filters">';
        echo '<input name="q" value="' . Util::h($pager['q'] ?? '') . '" placeholder="' . self::t('table.search', 'Поиск') . '">';
        echo '<select name="group_id" aria-label="' . Util::h(self::t('groups.title', 'Группы')) . '">';
        self::selectOption('0', self::t('cameraFilter.allGroups', 'Все группы'), (string)(int)($pager['group_id'] ?? 0));
        foreach ($groups as $group) {
            self::selectOption((string)$group['id'], (string)($group['display_name'] ?? $group['name']), (string)(int)($pager['group_id'] ?? 0));
        }
        echo '</select>';
        echo '<button>' . self::t('action.find', 'Найти') . '</button>';
        echo '<a class="camera-filter-reset" href="/admin/users">' . self::t('cameraFilter.reset', 'Сбросить') . '</a>';
        echo '</form>';
    }
}
