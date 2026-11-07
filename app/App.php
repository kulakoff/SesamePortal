<?php

declare(strict_types=1);

namespace SesamePortal;

final class App
{
    use VideoWallPages;
    use PortalApi;
    use MapPages;
    use DashboardApi;
    use UsersApi;
    use GroupsApi;
    use ServersApi;
    use CamerasApi;
    use AgentsApi;
    use AuditApi;
    use DashboardPages;
    use SettingsPages;
    use DvrPages;
    use LoginPages;
    use UsersPages;
    use GroupsPages;
    use DataHelpers;
    use AgentsPages;
    use FormsUi;
    use CamerasPages;
    use CameraImportPages;
    use CameraFields;
    use AuditPages;
    use ViewerPages;
    use AuthBackend;
    use LayoutUi;
    use GroupTreeUi;
    use ListQueries;
    use TablesUi;
    use PlaybackUrls;
    use CameraStatus;

    private static function t(string $key, string $fallback): string
    {
        return I18n::t($key, $fallback);
    }

    public static function run(): void
    {
        DB::migrate();
        $path = Util::path();
        // Machine API: authenticate its Bearer credential, without browser session/CSRF.
        if ($path === RbtAuthBackend::REGISTRATION_PATH) {
            [$status, $payload] = RbtAuthBackend::registerRequest();
            self::apiJson($payload, $status);
        }
        Auth::user();
        Csrf::verify();
        I18n::bootstrap();

        if ($path === '/api/portal/v1' || str_starts_with($path, '/api/portal/v1/')) {
            self::apiPortalV1();
            return;
        }

        match ($path) {
            '/login' => self::login(),
            '/logout' => self::logout(),
            '/settings' => self::personalSettings(),
            '/admin/dashboard' => self::dashboard(),
            '/admin/users' => self::users(),
            '/admin/groups' => self::groups(),
            '/admin/servers' => self::servers(),
            '/admin/agents/snapshot' => self::agentSnapshotProxy(),
            '/admin/agents' => self::agents(),
            '/admin/cameras' => self::cameras(),
            '/admin/cameras/import' => self::cameraImport(),
            '/admin/audit' => self::audit(),
            '/admin/settings' => self::settings(),
            '/viewer/map' => self::viewer('map'),
            '/viewer/map/google-session' => self::googleMapSession(),
            '/viewer/map/google-attribution' => self::googleMapAttribution(),
            '/viewer/preview' => self::previewProxy(),
            '/viewer/player' => self::player(),
            '/video-walls', '/video-walls/edit', '/video-walls/view', '/video-walls/stream' => self::videoWallsPage(),
            '/favorite/toggle' => self::toggleFavorite(),
            '/api/sesamedvr/auth' => self::authBackend(),
            default => self::viewer('mosaic'),
        };
    }
}
