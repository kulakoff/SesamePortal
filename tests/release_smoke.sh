#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREVIOUS_REF="${1:-}"
if [[ $# -gt 1 ]]; then
  echo "Usage: bash tests/release_smoke.sh [previous-git-ref]" >&2
  exit 2
fi
WORK="$(mktemp -d "${TMPDIR:-/tmp}/portal-release-test.XXXXXX")"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

# Schema upgrades may add columns and empty tables, but must preserve every
# existing row and field in both the shared database and a restored backup.
assert_backup_compatible() {
  php -r '
    $before = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["tables"];
    $after = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR)["tables"];
    foreach ($before as $table => $rows) {
        if (!array_key_exists($table, $after) || count($rows) !== count($after[$table])) {
            throw new RuntimeException("Backup row count changed: " . $table);
        }
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                if (!array_key_exists($column, $after[$table][$index]) || $after[$table][$index][$column] !== $value) {
                    throw new RuntimeException("Backup value changed: " . $table . "." . $column . " row " . $index);
                }
            }
        }
    }
    foreach (array_diff_key($after, $before) as $table => $rows) {
        if ($rows !== []) {
            throw new RuntimeException("New backup table unexpectedly contains data: " . $table);
        }
    }
  ' "$1" "$2"
}

bash "$ROOT/scripts/package-release.sh" --version module-smoke --output "$WORK/release.tar.gz"
mkdir "$WORK/releases"
tar -xzf "$WORK/release.tar.gz" -C "$WORK/releases"
RELEASE="$WORK/releases/sesame-portal"
test ! -e "$RELEASE/var/config.php"
diff -r "$ROOT/app" "$RELEASE/app"
for asset in manifest.webmanifest sw.js offline.html assets/pwa.js assets/pwa/icon-180.png assets/pwa/icon-192.png assets/pwa/icon-512.png; do
  cmp "$ROOT/public/$asset" "$RELEASE/public/$asset"
done
php "$RELEASE/tests/module_bootstrap.php"

# Exercise a full release through the same current/shared-state layout as the updater.
PREVIOUS_RELEASE="$RELEASE"
if [[ -n "$PREVIOUS_REF" ]]; then
  PREVIOUS_RELEASE="$WORK/previous"
  mkdir "$PREVIOUS_RELEASE"
  git -C "$ROOT" archive "$PREVIOUS_REF" | tar -x -C "$PREVIOUS_RELEASE"
fi
ln -s "$PREVIOUS_RELEASE" "$WORK/current"
export SESAME_PORTAL_STATE_DIR="$WORK/state"
export SESAME_PORTAL_DB_DSN="sqlite:$WORK/state/portal.sqlite"
export SESAME_PORTAL_CONFIG="$WORK/state/config.php"
export SESAME_PORTAL_SECRET="release-smoke-only"
export SESAME_PORTAL_UPDATE_AUTO_CHECK=0
mkdir -p "$SESAME_PORTAL_STATE_DIR"
php "$WORK/current/bin/portal" migrate
php "$WORK/current/bin/portal" create-admin release-test release-test-password
php "$WORK/current/bin/portal" backup "$WORK/before.json"

mkdir "$WORK/next"
tar -xzf "$WORK/release.tar.gz" -C "$WORK/next"
ln -s "$WORK/next/sesame-portal" "$WORK/current.next"
php -r 'if (!rename($argv[1], $argv[2])) exit(1);' "$WORK/current.next" "$WORK/current"
php "$WORK/current/tests/module_bootstrap.php"
php "$WORK/current/bin/portal" migrate
php "$WORK/current/bin/portal" backup "$WORK/after.json"
assert_backup_compatible "$WORK/before.json" "$WORK/after.json"

export SESAME_PORTAL_STATE_DIR="$WORK/restored"
export SESAME_PORTAL_DB_DSN="sqlite:$WORK/restored/portal.sqlite"
export SESAME_PORTAL_CONFIG="$WORK/restored/config.php"
mkdir -p "$SESAME_PORTAL_STATE_DIR"
php "$WORK/current/bin/portal" migrate
php "$WORK/current/bin/portal" restore "$WORK/before.json"
php "$WORK/current/bin/portal" backup "$WORK/restored.json"
assert_backup_compatible "$WORK/before.json" "$WORK/restored.json"
echo "release smoke: packaged modules, current symlink, shared state, migration and CLI backup/restore passed"
