#!/usr/bin/env bash
# Run after the clean CI checkout builds assets and installs locked --no-dev PHP dependencies.
# Produces a deployment candidate only; never reads a deployed panel or credentials.
set -euo pipefail

PANEL_ROOT="$(git rev-parse --show-toplevel)"
cd "$PANEL_ROOT"
PANEL_COMMIT="$(git rev-parse HEAD)"
PANEL_EPOCH="$(git show -s --format=%ct HEAD)"
PANEL_OUTPUT="${1:?Pass an absolute artifact output directory}"
[[ "$PANEL_OUTPUT" = /* ]] || { echo 'Artifact output must be absolute' >&2; exit 1; }
test -s public/assets/manifest.json
test -s vendor/autoload.php
test ! -e vendor/phpunit
git diff --quiet HEAD -- app bootstrap config database public resources routes storage artisan composer.json composer.lock package.json yarn.lock

PANEL_STAGE="$(mktemp -d)"
trap 'rm -rf -- "$PANEL_STAGE"' EXIT
git archive HEAD app bootstrap config database public resources routes storage artisan \
  composer.json composer.lock package.json yarn.lock LICENSE.md .env.example | tar -x -C "$PANEL_STAGE"
cp -a public/assets/. "$PANEL_STAGE/public/assets/"
cp -a vendor "$PANEL_STAGE/vendor"

python3 - "$PANEL_STAGE" "$PANEL_COMMIT" <<'PY'
import hashlib
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
commit = sys.argv[2]
config = root / 'config/app.php'
source = config.read_text()
old = "'version' => 'canary'"
if source.count(old) != 1:
    raise SystemExit('Review version stamping: expected exactly one canary version')
config.write_text(source.replace(old, "'version' => '1.15.1'"))

def digest(relative):
    return hashlib.sha256((root / relative).read_bytes()).hexdigest()

metadata = {
    'upstreamVersion': '1.15.1',
    'sourceCommit': commit,
    'composerLockSha256': digest('composer.lock'),
    'yarnLockSha256': digest('yarn.lock'),
    'assetsManifestSha256': digest('public/assets/manifest.json'),
    'versionStamp': "config/app.php: canary -> 1.15.1",
}
(root / 'hostvault-release.json').write_text(json.dumps(metadata, indent=2) + '\n')
if (root / '.env').exists() or (root / '.env.ci').exists():
    raise SystemExit('Runtime/test environment must not enter the artifact')
PY

mkdir -p "$PANEL_OUTPUT"
(
  cd "$PANEL_STAGE"
  find . -type f ! -path './SHA256SUMS' -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
  tar --sort=name --mtime="@$PANEL_EPOCH" --owner=0 --group=0 --numeric-owner \
    -czf "$PANEL_OUTPUT/panel-$PANEL_COMMIT.tar.gz" .
)
cp "$PANEL_STAGE/hostvault-release.json" "$PANEL_OUTPUT/hostvault-release.json"
(
  cd "$PANEL_OUTPUT"
  sha256sum "panel-$PANEL_COMMIT.tar.gz" > SHA256SUMS
)
