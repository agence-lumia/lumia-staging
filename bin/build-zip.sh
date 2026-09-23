#!/usr/bin/env bash
# Construit l'archive installable et son empreinte :
#   dist/lumia-staging-<version>.zip  (dossier racine lumia-staging/)
#   dist/SHA256SUMS                   (vérifiée par l'updater avant installation)
# Prérequis : `npm ci` fait. La version doit déjà être écrite (bin/set-version.sh).
set -euo pipefail

cd "$(dirname "$0")/.."
VERSION="$(sed -nE "s/^define\( 'LMV_VERSION', '([^']+)' \);/\1/p" lumia-staging.php)"
[[ -n "$VERSION" ]] || { echo "LMV_VERSION introuvable" >&2; exit 1; }

npm run build

rm -rf dist
mkdir -p dist/package/lumia-staging
rsync -a --exclude-from=.distignore ./ dist/package/lumia-staging/

# Garde-fous : l'archive doit contenir le JS compilé, jamais les outils de dev.
test -f dist/package/lumia-staging/build/admin.js
test ! -e dist/package/lumia-staging/vendor
test ! -e dist/package/lumia-staging/node_modules

(cd dist/package && zip -rqX "../lumia-staging-${VERSION}.zip" lumia-staging)
(cd dist && sha256sum "lumia-staging-${VERSION}.zip" > SHA256SUMS && cat SHA256SUMS)
