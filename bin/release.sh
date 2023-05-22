#!/usr/bin/env bash

# Exit if any command fails
set -eo pipefail

RELEASE_VERSION=$1

FILENAME_PREFIX="Plugin_Shopware6_"
FOLDER_PREFIX="MltisafeMultiSafepay"
RELEASE_FOLDER=".dist"

# If tag is not supplied, latest tag is used
if [ -z "$RELEASE_VERSION" ]
then
  RELEASE_VERSION=$(git describe --tags --abbrev=0)
fi

# Remove old folder
rm -rf "$RELEASE_FOLDER"

# Create release
mkdir "$RELEASE_FOLDER"
git archive --format zip -9 --prefix="$FOLDER_PREFIX"/ --output "$RELEASE_FOLDER"/"$FILENAME_PREFIX""$RELEASE_VERSION".zip "$RELEASE_VERSION"

# Unzip for generating composer autoloader
cd "$RELEASE_FOLDER"
unzip "$FILENAME_PREFIX""$RELEASE_VERSION".zip
rm "$FILENAME_PREFIX""$RELEASE_VERSION".zip

# Remove composer grumphp dependency because a conflict with the minimum php requirement for a release
composer remove phpro/grumphp --working-dir="$FOLDER_PREFIX" --update-no-dev --dev --no-update

# Remove shopware temporary so we don't have all the Shopware requirements in the plugin
composer remove shopware/administration  shopware/storefront shopware/core --working-dir="$FOLDER_PREFIX" --update-no-dev

# Add shopware back in the composer.json but not in the vendor folder
composer require shopware/administration:^6.4  shopware/storefront:^6.4 shopware/core:^6.4 --working-dir="$FOLDER_PREFIX" --no-update

# zip everything
zip -9 -r "$FILENAME_PREFIX""$RELEASE_VERSION".zip "$FOLDER_PREFIX" -x "$FOLDER_PREFIX""/composer.lock"
