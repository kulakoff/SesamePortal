# SesamePortal

Languages: [English](README.md) | [Русский](README.ru.md)

SesamePortal is a local PHP surveillance portal for SesameDVR installations. It
manages DVR servers, users, groups, cameras, per-user playback tokens, favorites,
and provides camera list, map and saved video wall pages backed by a SesameDVR auth backend.

## Local Development

```bash
php bin/portal migrate
php bin/portal create-admin admin admin123
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

Default local state is stored in `var/portal.sqlite`. Production installs use
`/var/lib/sesame-portal`.

SQLite is the default storage backend. PostgreSQL/MySQL can be selected through
config or environment variables:

```php
'db_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=sesame_portal',
'db_user' => 'sesame_portal',
'db_password' => 'secret',
```

The same values can be passed as `SESAME_PORTAL_DB_DSN`,
`SESAME_PORTAL_DB_USER`, and `SESAME_PORTAL_DB_PASSWORD`.

The UI supports the same language set as SesameDVR: `ru`, `en`, `de`, `fr`,
`es`, `it`, `pt`, `bg`, `pl`, `zh`, `ja`, `ko`, `ar`, and `hy`. The default
language is configured with `locale` or `SESAME_PORTAL_LOCALE`; users can
switch from the language dropdown or with `?lang=<code>`.

## Installed App and Remembered Login

Install SesamePortal as a PWA to launch the same portal from a home-screen icon
in its own window. Open the HTTPS portal and select **Install app** on the login
page or in the navigation. On iPhone/iPad, use Safari → Share → Add to Home Screen;
on Android, installation is also available in the browser menu.

On phones, an install suggestion appears above the camera list. **Later** hides
it for 30 days while the menu entry stays available. Installed app mode hides
installation controls. Android uses the native prompt when the browser provides
one; otherwise the button opens platform-specific steps. iPhone instructions
show Safari's installation steps. The sheet can copy the portal's clean root
URL, without player parameters or tokens, for opening it outside a messenger.

Select **Remember me** when signing in on a personal device. The server does not
impose a fixed login lifetime: access lasts until logout, password change,
account blocking/deletion, or revocation. Closing the app, restarting the server,
or garbage-collecting PHP sessions does not require another login. Leaving the
checkbox off retains ordinary browser-session behavior.

The app does not store the password. Each remembered login gets a random device
token, stored only as a hash in `remembered_logins`. Its cookie uses `HttpOnly`,
`SameSite=Lax`, and `Secure` with HTTPS. Logout revokes the current device token;
password changes and account blocking through the UI/API revoke all remembered
logins for that account. CLI backups exclude device tokens, and restoring a
backup clears existing remembered logins.

Browser storage limits still apply. Chrome caps cookie retention at 400 days;
Portal renews the cookie during use without asking for a password. Clearing site
data, losing app storage, or exceeding browser retention can require a new login.
See [Chrome's cookie limit](https://developer.chrome.com/blog/cookie-max-age-expires).

`remember_login_enabled` defaults to `true`. Behind a TLS-terminating reverse
proxy, set `base_url` to the public `https://` URL or configure PHP's `HTTPS`
parameter correctly. Client-supplied `X-Forwarded-Proto` is not automatically
trusted.

The service worker caches only a public offline explanation. It never caches
authenticated pages, camera images, video, API responses or playback tokens.
Video requires access to the server. `/manifest.webmanifest` and `/sw.js` are
public. Deploy at the HTTPS site root; localhost is supported for development,
but remote plain HTTP does not support PWA functionality.

Run `bash tests/http_smoke.sh`, `bash tests/release_smoke.sh`, and
`node tests/pwa_browser.cjs` (requires Playwright and installed Chrome). The browser
test uses a temporary database and checks installability, restart, revocation,
cookie renewal, mobile layout and offline behavior.

## Camera Modes

Each camera can run in one of three modes:

- `managed`: SesamePortal writes the stream configuration to the selected
  SesameDVR server through the management API. The stream title
  (`display_name`/`displayName`) is sent as the stream `displayName`;
  `dvr_stream_name` stays the URL-safe technical stream name used by DVR
  endpoints.
- `edge_agent`: SesamePortal creates a push stream on the DVR with
  `publisherKind=agent`, linked to `agent_id` and `agent_camera_id`.
- `read_only`: SesamePortal does not change DVR configuration and only uses the
  selected DVR server plus `dvr_stream_name` for preview, auth, and playback.

Opening an existing camera's settings reads its current stream configuration
from DVR. Saving sends only fields changed relative to that form's snapshot;
unchanged settings, including concurrent DVR edits, are not sent back. Each tab
has its own snapshot. If DVR cannot be read, managed-camera saving is blocked
instead of writing the cached configuration. API updates forward only explicitly
supplied fields. Creation and the separate manual sync action still send the
full Portal-managed configuration.

The administrative camera table refreshes archive depth and recording status
with one stream-list read per selected DVR before filtering and pagination.
These values update only the local display metadata, never DVR configuration.
Observations are stored separately so unsent `skipSync` edits remain intact.
Opening a camera form also refreshes this metadata. An unavailable DVR or
missing stream leaves the last known values intact and shows a table warning.

Per camera, Portal can add an HTML/CSS watermark with the current user's login
over the Portal player and control its intensity. The video stream is not
transcoded for this.

## Viewer UI

The **List** page (formerly Mosaic, still at `/`) supports all/favorites/group filtering, case-insensitive search by
camera title or technical stream name, pagination, and a 2-6 cameras-per-row
density switch. Page size follows the selected density: 4/6/12/15/18 cameras for
2/3/4/5/6 columns. It uses fixed 16:9 camera cards. Preview images are
preloaded behind a loader and then swapped in, so refreshes do not show
half-loaded images. The preview refresh interval is configurable in **Personal settings → List**,
including an `Off` mode. If a DVR stream is offline, the card shows `Stream
unavailable`; an old preview may still be shown with that status overlay.

The map view supports the same filters and search. It auto-fits the current
camera set, clusters nearby cameras at lower zoom levels, shows camera
direction/FOV markers, and exposes the same favorite toggle as the list.

**Video walls** (`/video-walls`) are named, personal camera layouts. Administrators
can manage all walls; other users can only manage their own. The editor provides
a group tree, search, drag-and-drop/button ordering and a 1×1 to 6×6 grid.
Selected cameras must fit the grid. Each cell uses a separate DVR embed
player; Portal does not transcode video. Walls support shared archive seeking,
play/pause, 0.5x-8x speed, LIVE and full screen. One embed-style timeline combines
recordings and events from all cameras into two logical-OR lanes; gaps and
buffering do not move the other cameras. Captions overlay video, tiles have no
spacing, and fullscreen playback controls hide when idle.
Shared playback requires an updated DVR with wall-control protocol v1.
Users with hidden archive have live controls only. Walls
preserve camera watermarks, and suspend offscreen players and hidden tabs.
Remounting a player rechecks access, obtains the current user token and applies
the shared playback position. See [video wall playback](docs/VIDEO-WALL-PLAYBACK.md).
Group inheritance and the administrative **Cameras** page remain unchanged.

All UI timestamps are rendered in the browser timezone. The server stores and
exchanges timestamps as absolute values.

## Screenshots

### SesameDVR

| Streams and playback | Monitoring | ONVIF |
| --- | --- | --- |
| <img src="docs/screenshots/sesamedvr-streams.jpg" alt="SesameDVR streams and player" width="280"> | <img src="docs/screenshots/sesamedvr-monitoring.jpg" alt="SesameDVR monitoring" width="280"> | <img src="docs/screenshots/sesamedvr-onvif.jpg" alt="SesameDVR ONVIF devices" width="280"> |

### SesamePortal: Desktop

| Dashboard | Mosaic | Map | Player |
| --- | --- | --- | --- |
| <img src="docs/screenshots/sesameportal-desktop-dashboard.jpg" alt="SesamePortal desktop dashboard" width="220"> | <img src="docs/screenshots/sesameportal-desktop-mosaic.jpg" alt="SesamePortal desktop mosaic" width="220"> | <img src="docs/screenshots/sesameportal-desktop-map.jpg" alt="SesamePortal desktop map" width="220"> | <img src="docs/screenshots/sesameportal-desktop-player.jpg" alt="SesamePortal desktop player" width="220"> |

### SesamePortal: Mobile

| Mosaic | Map | Player |
| --- | --- | --- |
| <img src="docs/screenshots/sesameportal-mobile-mosaic.jpg" alt="SesamePortal mobile mosaic" width="180"> | <img src="docs/screenshots/sesameportal-mobile-map.jpg" alt="SesamePortal mobile map" width="180"> | <img src="docs/screenshots/sesameportal-mobile-player.jpg" alt="SesamePortal mobile player" width="180"> |

## Production Install

```bash
sudo bash scripts/install.sh \
  --domain portal.example.com \
  --email admin@example.com \
  --admin-login admin \
  --admin-password 'change-me-now'
```

The installer creates an nginx site, connects the detected php-fpm socket,
initializes SQLite, creates the first admin user, and can issue a Let's Encrypt
certificate through `certbot certonly --webroot`. It writes only the
SesamePortal site file and does not let certbot rewrite existing nginx site
configs. It also installs `/usr/local/sbin/sesame-portal-update` and a sudoers
rule so an admin can update Portal from the web UI under `Settings`.

For repair/update runs against an existing database:

```bash
sudo bash scripts/install.sh \
  --domain portal.example.com \
  --email admin@example.com \
  --repair
```

The installer backs up the current release, nginx site, cron entry, config, and
SQLite files before applying changes. If a step fails, it restores those files
and reloads nginx/php-fpm when possible.

## First Login

Open the portal URL after installation:

```text
https://portal.example.com
```

Use the credentials passed to the installer:

- login: the value of `--admin-login`, default `admin`;
- password: the value of `--admin-password`.

Example from the command above:

```text
login: admin
password: change-me-now
```

During `--repair` runs, the installer does not change existing admin users when
`--admin-password` is omitted.

## SesameDVR Trial Install

SesamePortal is normally connected to one or more SesameDVR servers. For GitHub
evaluation installs, use this public SesameDVR trial key:

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh | sudo bash -s -- --license-key SDVR-TRIAL-85GT2-A7YYD-HSSEN-YW98U
```

For public HTTPS access, pass the target DVR domain and ACME email:

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh \
  | sudo bash -s -- \
      --license-key SDVR-TRIAL-85GT2-A7YYD-HSSEN-YW98U \
      --publish-service \
      --publish-server-name dvr.example.com \
      --publish-acme \
      --acme-email admin@example.com
```

More details are in
[`docs/SESAME-DVR-TRIAL-INSTALL.ru.md`](docs/SESAME-DVR-TRIAL-INSTALL.ru.md).

Additional SesameDVR documentation:

- [SesameDVR feature and product description](docs/SESAME-DVR-PRODUCT-DESCRIPTION.en.md)
- [SesameDVR user guide](docs/sesame-dvr-user-guide.en.md)
- [SesameDVR HTTP API reference (RU)](docs/sesame-dvr-api.ru.md)
- [SesameDVR storage configuration (RU)](docs/dvr-storage-configuration.ru.md)
- [SesameDVR Prometheus / OpenMetrics endpoint (RU)](docs/prometheus-metrics-endpoint.ru.md)
- [SesameDVR BEAM hot patch manifest (RU)](docs/beam-hot-patch-manifest.ru.md)
- [SesameDVR DVR-side failover runbook (RU)](docs/dvr-cluster-failover-runbook.ru.md)

## CLI

```bash
php bin/portal migrate
php bin/portal create-admin <login> <password>
php bin/portal rotate-tokens
php bin/portal rotate-secrets
php bin/portal backup /path/to/backup.json
php bin/portal restore /path/to/backup.json
```

`rotate-secrets` re-encrypts stored SesameDVR management tokens with the
configured `crypto_primary_key` while preserving access to older key ids for
rotation windows.

`rotate-tokens`, already scheduled by the installer, also removes expired RBT
signing secrets.

For RBT playback authorization, configuration, Redis rotation and the shared
registration API contract, see [RBT authbackend integration (RU)](docs/rbt-authbackend.ru.md).

## Code Structure

`app/Portal.php` remains the shared bootstrap for the web application, CLI and
tests. Runtime classes live in individual files; `App` composes resource API,
page, Auth Backend, UI and support modules. See [PHP module architecture](docs/ARCHITECTURE.md)
for ownership boundaries and release requirements.

## Checks

```bash
find app -name '*.php' -exec php -l {} \;
php tests/module_bootstrap.php
php tests/camera_settings.php
bash -n scripts/install.sh
tests/http_smoke.sh
bash tests/release_smoke.sh
```
