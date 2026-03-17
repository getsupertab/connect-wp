#!/usr/bin/env bash
#
# Builds a distribution zip for the WordPress plugin.
# Usage: bash build-zip.sh <version>
#
set -euo pipefail

VERSION="${1:?Usage: build-zip.sh <version>}"
PLUGIN_SLUG="supertab-connect"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

# Copy everything to a staging directory
rm -rf "/tmp/${PLUGIN_SLUG}"
mkdir -p "/tmp/${PLUGIN_SLUG}"
rsync -a --exclude='.git' ./ "/tmp/${PLUGIN_SLUG}/"

# Remove files listed in .distignore
while IFS= read -r line || [ -n "$line" ]; do
	line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
	[ -z "$line" ] && continue
	[[ "$line" == \#* ]] && continue

	cd "/tmp/${PLUGIN_SLUG}"
	rm -rf $line
	cd - > /dev/null
done < .distignore

# Create the zip (top-level dir = supertab-connect/)
cd /tmp
zip -r "${OLDPWD}/${ZIP_NAME}" "${PLUGIN_SLUG}/"

echo "Built ${ZIP_NAME}"
