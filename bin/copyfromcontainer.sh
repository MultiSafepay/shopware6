#!/bin/bash
[ -z "$1" ] && echo "Please specify a directory or file to copy from container (ex. vendor, --all)" && exit

REAL_SRC=$(cd -P "application" && pwd)
EXCLUDE_PATH="custom/plugins/MltisafeMultiSafepay"

if [ "$1" == "--all" ]; then
  # Create a temporary directory to handle exclusions
  TMP_DIR=$(mktemp -d)

  # Use docker cp to get all files first
  docker cp "$(docker compose ps -q app|awk '{print $1}')":/var/www/html/./ "$TMP_DIR/"

  # Use rsync with exclude to copy everything except the excluded path
  rsync -av --progress --exclude="/${EXCLUDE_PATH}/**" "$TMP_DIR/" "$REAL_SRC/"

  # Clean up the temporary directory
  rm -rf "${TMP_DIR:?}"

  echo "Completed copying all files from container to host (excluding $EXCLUDE_PATH)"
else
  # Check if the requested path is inside the excluded path
  if [[ "$1" == "$EXCLUDE_PATH"* || "$1" == *"$EXCLUDE_PATH"* ]]; then
    echo "Error: Cannot copy '$1' because it's in the excluded path"
    exit 1
  fi

  if [ -f "$1" ]; then
    docker cp "$(docker compose ps -q app|awk '{print $1}')":/var/www/html/"$1" "$REAL_SRC/$1"
  else
    docker cp "$(docker compose ps -q app|awk '{print $1}')":/var/www/html/"$1" "$REAL_SRC/$(dirname "$1")"
  fi
  echo "Completed copying $1 from container to host"
fi
