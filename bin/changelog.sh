#!/usr/bin/env bash
# CHANGELOG.md (Keep a Changelog, section « [Non publié] » en tête).
#
#   bin/changelog.sh section <nom>            corps d'une section (« Non publié », « 1.2.3 »)
#   bin/changelog.sh promote <version> <date> « Non publié » devient « [version] — date »
#                                             (sans effet si la section est vide)
set -euo pipefail

cd "$(dirname "$0")/.."
FILE=CHANGELOG.md

section() {
	awk -v name="$1" '
		/^## \[/ { inside = index($0, "## [" name "]") == 1; next }
		inside { print }
	' "$FILE" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}'
}

case "${1:-}" in
	section)
		section "${2:?nom de section}"
		;;
	promote)
		VERSION="${2:?version}"
		DATE="${3:?date}"
		if [[ -z "$(section 'Non publié' | tr -d '[:space:]')" ]]; then
			echo "Rien de non publié : CHANGELOG inchangé."
			exit 0
		fi
		if grep -q "^## \[${VERSION}\]" "$FILE"; then
			echo "La section ${VERSION} existe déjà." >&2
			exit 1
		fi
		sed -i "s/^## \[Non publié\]$/## [Non publié]\n\n## [${VERSION}] — ${DATE}/" "$FILE"
		echo "CHANGELOG : Non publié → ${VERSION}"
		;;
	*)
		echo "usage: bin/changelog.sh section <nom> | promote <version> <date>" >&2
		exit 1
		;;
esac
