#!/bin/sh
# Fixed upstream releases for CVE-2026-109618 and CVE-2026-109619:
# https://www.php.net/archive/2026.php (2026-10-21).
# No unreviewed vendor backport or prerelease exceptions.
set -eu
[ "$#" -eq 1 ] || { echo 'Usage: check-php-runtime.sh 8.2|8.3' >&2; exit 1; }
branch="$1"
case "$branch" in
  8.2) minimum=35 ;;
  8.3) minimum=36 ;;
  *) echo "Unsupported PHP branch: $branch" >&2; exit 1 ;;
esac
banner="$(php -v)"
version="$(printf '%s\n' "$banner" | sed -n '1s/^PHP \([^ ]*\) .*/\1/p')"
case "$version" in
  "$branch".*) patch="${version#"$branch".}" ;;
  *) patch='' ;;
esac
case "$patch" in
  ''|*[!0-9]*) echo "FATAL: require stable PHP $branch.$minimum or newer on branch $branch; found '$version'" >&2; exit 1 ;;
esac
if ! [ "$patch" -ge "$minimum" ]; then
  echo "FATAL: vulnerable PHP $version; require $branch.$minimum or newer on branch $branch" >&2
  exit 1
fi
printf 'Verified PHP %s meets the security minimum\n' "$version"
