#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 TaskNest contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Switch the app icon to one of the candidates in brand/candidates/.
#
#   bash brand/apply.sh c-bold-bowl-check
#
# Writes img/app.svg (white, coloured header / app menu), img/app-dark.svg (dark, light
# backgrounds), img/tasknest.svg (dark, what the theming app turns into the tab icon) and
# img/favicon.svg (own blue background), then bumps the patch version in appinfo/info.xml:
# the icon URLs carry the version, so without a bump both the browser and the server keep
# serving the old one.

set -euo pipefail

id=${1:-}
root=$(cd "$(dirname "$0")/.." && pwd)
dir="$root/brand/candidates"

if [ -z "$id" ] || [ ! -f "$dir/$id-white.svg" ]; then
	echo "usage: bash brand/apply.sh <candidate-id>" >&2
	echo "available:" >&2
	(cd "$dir" && ls -- *-white.svg 2>/dev/null | sed 's/-white\.svg$//; s/^/  /' >&2)
	exit 1
fi

cp "$dir/$id-white.svg" "$root/img/app.svg"
cp "$dir/$id-dark.svg" "$root/img/app-dark.svg"
cp "$dir/$id-dark.svg" "$root/img/tasknest.svg"
cp "$dir/$id-favicon.svg" "$root/img/favicon.svg"

version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$root/appinfo/info.xml")
major=${version%%.*}
rest=${version#*.}
minor=${rest%%.*}
patch=${rest#*.}
next="$major.$minor.$((patch + 1))"
sed -i "s:<version>$version</version>:<version>$next</version>:" "$root/appinfo/info.xml"

echo "icon: $id"
echo "version: $version -> $next"
echo "next: npm run build   (and regenerate img/favicon.png if you want the PNG fallback refreshed)"
