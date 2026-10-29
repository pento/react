#!/usr/bin/env bash
#
# Check that the WordPress.org SVN deploy (.distignore) and the GitHub release
# ZIP (package.json "files", via wp-scripts plugin-zip) ship the same runtime
# files, and that those include everything the plugin needs to load.
#
# Mirrors the packaging steps in georgestephanis/plugins' deploy.yml and
# scripts/build-zip.sh. Run from the plugin root after `npm run build`.
set -euo pipefail

stage="$(mktemp -d)"
trap 'rm -rf "$stage"; rm -f react.zip' EXIT

excludes=(--exclude=.git/ --exclude=.github/ --exclude=node_modules/ --exclude=.distignore)
while IFS= read -r line; do
	[[ -z "$line" || "$line" == \#* ]] && continue
	excludes+=(--exclude="$line")
done < .distignore

rsync -rc "${excludes[@]}" ./ "$stage/svn/"
( cd "$stage/svn" && find . -type f | sed 's#^\./##' | sort ) > "$stage/svn.txt"

npx wp-scripts plugin-zip > /dev/null
# npm-packlist always adds package.json and README.md; they're harmless in a ZIP.
unzip -Z1 react.zip | grep -v '/$' | sed 's#^react/##' \
	| grep -vxE 'package\.json|README\.md' | sort > "$stage/zip.txt"

status=0

if ! diff -u --label svn "$stage/svn.txt" --label zip "$stage/zip.txt"; then
	echo "::error::.distignore and package.json \"files\" ship different files (see diff above)."
	status=1
fi

for required in react.php readme.txt license.txt lib/class-react.php static/react.js static/react-admin.js static/emoji-data.json; do
	if ! grep -qxF "$required" "$stage/svn.txt"; then
		echo "::error::$required is missing from the distributed plugin."
		status=1
	fi
done

if [ "$status" -eq 0 ]; then
	echo "Distribution OK: $(wc -l < "$stage/svn.txt" | tr -d ' ') files."
fi

exit "$status"
