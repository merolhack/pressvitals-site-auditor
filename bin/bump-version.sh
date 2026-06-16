#!/usr/bin/env bash
# Usage: bin/bump-version.sh 1.3.0

set -e

if [ -z "$1" ]; then
    echo "Usage: $0 <version>"
    exit 1
fi

NEW_VERSION=$1
MAIN_FILE="omnihealth-site-auditor.php"
README_FILE="readme.txt"

if [ ! -f "$MAIN_FILE" ]; then
    echo "Run this from the project root."
    exit 1
fi

echo "Bumping version to $NEW_VERSION..."

# Update main plugin file
sed -i -E "s/Version:[ \t]+[0-9]+\.[0-9]+\.[0-9]+/Version:           $NEW_VERSION/" "$MAIN_FILE"
sed -i -E "s/define\( 'OHSA_VERSION', '[0-9]+\.[0-9]+\.[0-9]+' \);/define( 'OHSA_VERSION', '$NEW_VERSION' );/" "$MAIN_FILE"

# Update readme.txt
if [ -f "$README_FILE" ]; then
    sed -i -E "s/Stable tag:[ \t]+[0-9]+\.[0-9]+\.[0-9]+/Stable tag: $NEW_VERSION/" "$README_FILE"
fi

echo "Done! Please verify the changes and update the == Changelog == in readme.txt manually."
