<?php

declare(strict_types=1);

namespace SesamePortal;

trait LoginPages
{
    private static function login(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && Auth::user()) {
            Util::redirect('/');
        }
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (Auth::login((string)Util::post('login'), (string)Util::post('password'), Util::checkbox('remember') === 1)) {
                Util::redirect('/');
            }
            $error = self::t('login.invalid', 'Неверный логин или пароль');
        }

        self::layout(self::t('login.title', 'Вход'), function () use ($error) {
            echo '<section class="login-visual"><div><img src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"><p>' . self::t('login.subtitle', 'Портал видеонаблюдения SesameWare') . '</p></div>';
            echo '<div class="login-features"><span>' . Util::h(self::t('login.feature.secure', 'Безопасно')) . '</span><span>' . Util::h(self::t('login.feature.reliable', 'Надежно')) . '</span><span>' . Util::h(self::t('login.feature.efficient', 'Производительно')) . '</span></div></section>';
            echo '<section class="login-panel login-card">';
            if ($error) {
                echo '<div class="alert danger">' . Util::h($error) . '</div>';
            }
            echo '<form method="post" class="form">';
            echo Csrf::field();
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" autocomplete="username" required></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" autocomplete="current-password" required></label>';
            if (Auth::rememberEnabled()) {
                echo '<label class="check"><input type="checkbox" name="remember" value="1"' . (Util::checkbox('remember') ? ' checked' : '') . '> ' . Util::h(self::t('login.remember', 'Запомнить меня')) . '</label>';
                echo '<p class="muted">' . Util::h(self::t('login.rememberHint', 'Используйте только на личном устройстве. Кнопка «Выход» отменяет сохранение входа.')) . '</p>';
            }
            echo '<button class="primary">' . self::t('action.login', 'Войти') . '</button>';
            echo '</form>';
            self::pwaInstallControl();
            echo I18n::languageLinks() . '</section>';
        }, null);
    }

    private static function logout(): void
    {
        Auth::logout();
        Util::redirect('/login');
    }
}
