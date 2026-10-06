#!/bin/sh
set -eu

fail() {
  echo "TAMASYA runtime storage init: $*" >&2
  exit 73
}

prepare_dir() {
  path="$1"
  mode="$2"
  if [ -L "$path" ]; then
    fail "refusing symlink path: $path"
  fi
  if [ -e "$path" ] && [ ! -d "$path" ]; then
    fail "expected directory but found non-directory: $path"
  fi
  mkdir -p "$path"
  chown www-data:www-data "$path"
  chmod "$mode" "$path"
}

# These paths are mounted with volume.nocopy=true. A single one-shot initializer
# owns directory creation so parallel PHP-FPM replicas never race while Docker
# populates a fresh named volume from the image filesystem.
prepare_dir /var/lib/tamasya 0700
prepare_dir /var/lib/tamasya/outbox 0700
prepare_dir /var/lib/tamasya/backups 0700
prepare_dir /var/www/tamasya/uploads 0755
prepare_dir /var/www/tamasya/uploads/public-site 0755

echo "TAMASYA runtime storage initialized"
