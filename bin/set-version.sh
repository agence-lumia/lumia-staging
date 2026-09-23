#!/usr/bin/env bash
# Écrit la version partout où elle apparaît : en-tête du plugin, LMV_VERSION,
# package.json. Usage : bin/set-version.sh 1.2.3[-dev.4]
set -euo pipefail

VERSION="${1:?usage: bin/set-version.sh <version>}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
	echo "Version invalide : $VERSION" >&2
	exit 1
fi

cd "$(dirname "$0")/.."
sed -E -i "s/^( \* Version:[[:space:]]+).*/\1${VERSION}/" lumia-staging.php
sed -E -i "s/(define\( 'LMV_VERSION', ')[^']*(' \);)/\1${VERSION}\2/" lumia-staging.php
sed -E -i "0,/\"version\": \"[^\"]*\"/s//\"version\": \"${VERSION}\"/" package.json

grep -q "^ \* Version:[[:space:]]*${VERSION}$" lumia-staging.php
grep -q "define( 'LMV_VERSION', '${VERSION}' );" lumia-staging.php
echo "Version ${VERSION}"
