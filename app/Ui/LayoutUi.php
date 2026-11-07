<?php

declare(strict_types=1);

namespace SesamePortal;

trait LayoutUi
{
    private static function layout(string $title, callable $body, ?array $userOverride = [], string $bodyClass = '', bool $showChrome = true): void
    {
        $user = $userOverride === null ? null : Auth::user();
        echo '<!doctype html><html lang="' . Util::h(I18n::htmlLocale()) . '" dir="' . Util::h(I18n::dir()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . Util::h($title) . ' - SesamePortal</title>';
        echo '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
        echo '<link rel="manifest" href="/manifest.webmanifest"><link rel="apple-touch-icon" href="/assets/pwa/icon-180.png">';
        echo '<meta name="theme-color" content="#121212"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="SesamePortal">';
        echo '<script defer src="' . Util::h(self::assetUrl('/assets/pwa.js')) . '"></script>';
        echo '<link rel="stylesheet" href="' . Util::h(self::assetUrl('/assets/styles.css')) . '">';
        if (str_starts_with(Util::path(), '/video-walls')) {
            echo '<link rel="stylesheet" href="' . Util::h(self::assetUrl('/assets/video-walls.css')) . '">';
        }
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">';
        echo '</head><body' . ($bodyClass !== '' ? ' class="' . Util::h($bodyClass) . '"' : '') . '>';
        if ($user && $showChrome) {
            echo '<div class="shell"><aside class="sidebar">';
            echo '<a class="brand-logo-link" href="/"><img class="brand-logo-full" src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"></a>';
            echo '<div class="nav-section">' . Util::h(self::t('nav.section.view', 'Просмотр')) . '</div><nav class="nav">';
            $viewerFilter = (string)($_GET['filter'] ?? 'all');
            self::navLink('/', self::t('nav.mosaic', 'Список'), 'grid', Util::path() === '/' && $viewerFilter !== 'favorites');
            self::navLink('/video-walls', self::t('wall.title', 'Видеостены'), 'dashboard', str_starts_with(Util::path(), '/video-walls'));
            self::navLink('/viewer/map', self::t('nav.map', 'Карта'), 'map');
            self::navLink('/?filter=favorites', self::t('filter.favorites', 'Избранное'), 'star', ($_GET['filter'] ?? '') === 'favorites' && Util::path() === '/');
            self::navLink('/settings', self::t('settings.personalTitle', 'Личные настройки'), 'settings');
            echo '</nav>';
            if ($user['role'] === 'admin') {
                echo '<div class="nav-section">' . Util::h(self::t('nav.section.admin', 'Администрирование')) . '</div><nav class="nav">';
                self::navLink('/admin/dashboard', self::t('nav.dashboard', 'Dashboard'), 'dashboard');
                self::navLink('/admin/users', self::t('nav.users', 'Пользователи'), 'user');
                self::navLink('/admin/groups', self::t('nav.groups', 'Группы'), 'group');
                self::navLink('/admin/cameras', self::t('nav.cameras', 'Камеры'), 'camera', str_starts_with(Util::path(), '/admin/cameras'));
                self::navLink('/admin/servers', self::t('nav.dvr', 'DVR'), 'server');
                self::navLink('/admin/agents', self::t('nav.agents', 'Edge Agents'), 'agent');
                self::navLink('/admin/audit', self::t('nav.audit', 'Журнал'), 'audit');
                self::navLink('/admin/settings', self::t('nav.settings', 'Настройки'), 'settings');
                echo '</nav>';
            }
            echo '<div class="sidebar-foot">';
            self::pwaInstallControl();
            echo I18n::languageLinks() . '<a class="logout-link" href="/logout">' . self::icon('logout') . self::t('nav.logout', 'Выход') . '</a></div></aside>';
            $initial = strtoupper(substr((string)$user['login'], 0, 1) ?: 'U');
            echo '<main class="main workspace"><div class="topbar"><div><h1>' . Util::h($title) . '</h1></div><div class="user">' . Util::h($initial) . '</div></div>';
            if (Util::path() === '/') {
                self::pwaInstallSuggestion();
            }
            if ($user['role'] === 'admin') {
                self::portalUpdateBanner();
            }
            $body();
            echo '</main></div>';
        } else {
            echo '<main class="' . ($user ? 'workspace' : 'login-page') . '">';
            $body();
            echo '</main>';
        }
        if (!$user || $showChrome) {
            self::pwaInstallDialog();
        }
        echo '<script>window.SESAME_I18N = ' . json_encode(I18n::js(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.SESAME_CSRF = ' . json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script><script src="' . Util::h(self::assetUrl('/assets/app.js')) . '"></script>';
        if (str_starts_with(Util::path(), '/video-walls')) {
            echo '<script src="' . Util::h(self::assetUrl('/assets/video-wall-playback.js')) . '"></script>';
            echo '<script src="' . Util::h(self::assetUrl('/assets/video-walls.js')) . '"></script>';
        }
        echo '</body></html>';
    }

    private static function assetUrl(string $path): string
    {
        $file = Config::root() . '/public' . $path;
        if (!is_file($file)) {
            return $path;
        }

        return $path . '?v=' . filemtime($file);
    }

    private static function pwaInstallControl(): void
    {
        echo '<div class="pwa-install" data-pwa-controls hidden><button type="button" data-pwa-install aria-haspopup="dialog" aria-controls="pwa-install-dialog">' . self::icon('download') . '<span>' . Util::h(self::t('pwa.install', 'Установить приложение')) . '</span></button></div>';
    }

    private static function pwaInstallSuggestion(): void
    {
        echo '<section class="pwa-suggestion" data-pwa-controls data-pwa-suggestion hidden aria-label="' . Util::h(self::t('pwa.title', 'SesamePortal на вашем телефоне')) . '">';
        echo '<img src="/assets/pwa/icon-192.png" width="48" height="48" alt=""><div class="pwa-suggestion-copy"><strong>' . Util::h(self::t('pwa.title', 'SesamePortal на вашем телефоне')) . '</strong><p>' . Util::h(self::t('pwa.benefit', 'Открывайте камеры с иконки на главном экране.')) . '</p></div>';
        echo '<div class="pwa-suggestion-actions"><button type="button" class="primary" data-pwa-install aria-haspopup="dialog" aria-controls="pwa-install-dialog">' . Util::h(self::t('pwa.install', 'Установить приложение')) . '</button><button type="button" data-pwa-dismiss>' . Util::h(self::t('pwa.later', 'Позже')) . '</button></div></section>';
    }

    private static function pwaInstallDialog(): void
    {
        echo '<dialog id="pwa-install-dialog" class="pwa-dialog" data-pwa-help aria-labelledby="pwa-install-title" aria-describedby="pwa-install-description">';
        echo '<div class="pwa-dialog-heading"><img src="/assets/pwa/icon-192.png" width="52" height="52" alt=""><div><h2 id="pwa-install-title">' . Util::h(self::t('pwa.install', 'Установить приложение')) . '</h2><p id="pwa-install-description">' . Util::h(self::t('pwa.benefit', 'Открывайте камеры с иконки на главном экране.')) . '</p></div><button type="button" class="pwa-dialog-close" data-pwa-close aria-label="' . Util::h(self::t('pwa.close', 'Закрыть')) . '">×</button></div>';
        $platformSteps = [
            'ios' => [
                self::t('pwa.iosShare', 'В Safari нажмите «Поделиться» — квадрат со стрелкой вверх. Кнопка может быть в меню «…».'),
                self::t('pwa.iosHome', 'Выберите «На экран Домой». Если пункта не видно, прокрутите список действий.'),
                self::t('pwa.iosAdd', 'Если есть переключатель «Открывать как веб-приложение», включите его. Нажмите «Добавить».'),
            ],
            'android' => [
                self::t('pwa.androidMenu', 'Откройте меню браузера «⋮».'),
                self::t('pwa.androidInstall', 'Выберите «Установить приложение» или «Добавить на главный экран».'),
                self::t('pwa.androidConfirm', 'Подтвердите установку. Иконка SesamePortal появится на телефоне.'),
            ],
            'desktop' => [
                self::t('pwa.desktopMenu', 'Нажмите значок установки в адресной строке или откройте меню браузера.'),
                self::t('pwa.desktopInstall', 'Выберите установку SesamePortal и подтвердите действие.'),
            ],
        ];
        foreach ($platformSteps as $platform => $steps) {
            echo '<div data-pwa-platform="' . $platform . '" hidden><ol class="pwa-steps">';
            foreach ($steps as $index => $step) {
                echo '<li><span class="pwa-step-number" aria-hidden="true">' . ($index + 1) . '</span><span>';
                if ($platform === 'ios' && $index === 0) {
                    echo '<svg class="pwa-share-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9H5v12h14V9h-3M12 3v12M8 7l4-4 4 4"/></svg>';
                }
                echo Util::h($step) . '</span></li>';
            }
            echo '</ol>';
            if ($platform !== 'desktop') {
                echo '<p class="pwa-browser-hint">' . Util::h($platform === 'ios'
                    ? self::t('pwa.iosBrowser', 'Нет нужного пункта или сайт открыт внутри мессенджера? Скопируйте адрес ниже и откройте его в Safari.')
                    : self::t('pwa.androidBrowser', 'Нет нужного пункта или сайт открыт внутри мессенджера? Скопируйте адрес ниже и откройте его в Chrome.')) . '</p>';
            }
            echo '</div>';
        }
        echo '<div class="pwa-copy"><label for="pwa-install-url">' . Util::h(self::t('pwa.address', 'Адрес портала')) . '</label><div><input id="pwa-install-url" data-pwa-url type="url" readonly dir="ltr"><button type="button" data-pwa-copy>' . Util::h(self::t('pwa.copy', 'Скопировать')) . '</button></div><p data-pwa-copy-status role="status" aria-live="polite" data-copied="' . Util::h(self::t('pwa.copied', 'Адрес скопирован')) . '" data-select="' . Util::h(self::t('pwa.copyManually', 'Адрес выделен. Скопируйте его через меню устройства.')) . '"></p></div>';
        echo '<button type="button" class="primary pwa-dialog-done" data-pwa-close>' . Util::h(self::t('pwa.done', 'Понятно')) . '</button></dialog>';
    }

    private static function navLink(string $href, string $label, string $icon, ?bool $activeOverride = null): void
    {
        $path = Util::path();
        $hrefPath = (string)(parse_url($href, PHP_URL_PATH) ?: '/');
        $active = $activeOverride ?? ($path === $hrefPath);
        echo '<a class="' . ($active ? 'active' : '') . '" href="' . Util::h($href) . '">' . self::icon($icon) . '<span>' . Util::h($label) . '</span></a>';
    }

    private static function icon(string $name): string
    {
        $paths = [
            // Lucide Search, matching the existing inline icon helper.
            'search' => '<g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></g>',
            'pause' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M6 4h4v16H6zM14 4h4v16h-4z"/>',
            'play' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="m6 3 14 9-14 9V3z"/>',
            'grid' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
            'map' => '<path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2V6z"/><path d="M9 4v14M15 6v14"/>',
            'star' => '<path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3z"/>',
            'dashboard' => '<path d="M4 13h7V4H4v9zM13 20h7V4h-7v16zM4 20h7v-5H4v5z"/>',
            'user' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 20a8 8 0 0 1 16 0"/>',
            'group' => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM17 12a3 3 0 1 0 0-6"/><path d="M2 21a7 7 0 0 1 14 0M14 20a5 5 0 0 1 8 0"/>',
            'camera' => '<path d="M4 7h11a3 3 0 0 1 3 3v7H4V7z"/><path d="m18 11 4-3v8l-4-3"/>',
            'server' => '<path d="M4 6h16v5H4zM4 13h16v5H4z"/><path d="M8 8h.01M8 15h.01"/>',
            'agent' => '<path d="M12 3 4 7v10l8 4 8-4V7l-8-4z"/><path d="M8 9h8M8 13h8M10 17h4"/>',
            'audit' => '<path d="M6 3h12v18H6z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
            'settings' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m19.4 15 .6 2.2-2 3.4-2.2-.6a8 8 0 0 1-1.9 1.1L13.3 23h-4l-.6-1.9A8 8 0 0 1 6.8 20l-2.2.6-2-3.4.6-2.2A8 8 0 0 1 2 13.2L0 12l2-1.2A8 8 0 0 1 3.2 9l-.6-2.2 2-3.4 2.2.6A8 8 0 0 1 8.7 2.9L9.3 1h4l.6 1.9A8 8 0 0 1 15.8 4l2.2-.6 2 3.4-.6 2.2a8 8 0 0 1 1.1 1.8L22 12l-1.5 1.2a8 8 0 0 1-1.1 1.8z"/>',
            'logout' => '<path d="M10 4H5v16h5"/><path d="M14 8l4 4-4 4M18 12H9"/>',
            'edit' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="m14.5 7.5 2 2"/>',
            'check' => '<path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="M20 6 9 17l-5-5"/>',
            'sync' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h11a5 5 0 0 1 5 5M4 7l4-4M4 7l4 4M20 17H9a5 5 0 0 1-5-5m16 5-4-4m4 4-4 4"/>',
            'trash' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M8 7l1-3h6l1 3M7 7l1 14h8l1-14"/>',
            'key' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 1 0 2.8 1.2L21 5l-2-2-3.2 3.2A4 4 0 0 0 15 7zM9 13l-6 6m3-3 2 2"/>',
            'token-issue' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3.5 8.5h11V11a2 2 0 0 0 0 4v2.5h-11V15a2 2 0 0 0 0-4V8.5zM8 10.5v5M19 5v6M16 8h6"/>',
            'token-refresh' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 8.5h10.5V11a2 2 0 0 0 0 4v2.5H4V15a2 2 0 0 0 0-4V8.5zM8.5 10.5v5M20 6v4h-4M4 18v-4h4M18.8 10a6.5 6.5 0 0 0-10.2-3.4M5.2 14a6.5 6.5 0 0 0 10.2 3.4"/>',
            'token-revoke' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 5.5 6.2V12c0 4.8 2.6 8.3 6.5 9.8 3.9-1.5 6.5-5 6.5-9.8V6.2L12 3.5zM9 10l6 6M15 10l-6 6"/>',
            'ban' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M5 5a10 10 0 0 1 14 14M19 5A10 10 0 0 0 5 19M5 5l14 14"/>',
            'scan' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M8 12h8M12 8v8"/>',
            'diagnostics' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l2-6 4 12 2-6h6"/>',
            'download' => '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
        ];
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
    }
}
