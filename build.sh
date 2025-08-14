#!/bin/bash

# A modernized script to build Unraid plugin packages.
# It automatically packages the source, calculates the MD5 hash,
# and updates the .plg file with the new version and hash.

# --- Configuration ---
set -euo pipefail

# --- Globals ---
readonly ARCHIVE_DIR="./archive"
readonly SOURCE_DIR="./source"
readonly SCRIPT_NAME=$(basename "$0")

# --- Functions ---
usage() {
    echo "Usage: $SCRIPT_NAME <plugin_file.plg> [branch] [version]"
    exit 1
}

log() {
    local color_code=""
    case "${2:-}" in
        green)  color_code='\033[0;32m' ;;
        red)    color_code='\033[0;31m' ;;
        yellow) color_code='\033[0;33m' ;;
        blue)   color_code='\033[0;34m' ;;
    esac
    printf "${color_code}%s\033[0m\n" "$1"
}

# --- Main Script ---
if [[ "$#" -lt 1 || "$#" -gt 3 || "${1##*.}" != "plg" ]]; then
    usage
fi

PLUGIN_FILE="$1"
BRANCH="${2:-main}"
VERSION="${3:-$(date +%Y.%m.%d.%H%M%S)}"

SED_CMD="sed"
TAR_CMD="tar"
MD5_CMD="md5sum"
if [[ "$(uname)" == "Darwin" ]]; then
    SED_CMD="gsed"
    TAR_CMD="gtar"
    MD5_CMD="md5 -r"
fi

for cmd in "$SED_CMD" "$TAR_CMD" "dos2unix"; do
    if ! command -v "$cmd" &> /dev/null; then
        log "Error: Required command '$cmd' is not installed." "red"
        exit 1
    fi
done

NAME=$("$SED_CMD" -n 's/<!ENTITY[[:space:]]\+name[[:space:]]\+"\(.*\)">/\1/p' "$PLUGIN_FILE")
if [[ -z "$NAME" ]]; then
    log "Error: Could not extract plugin name from '$PLUGIN_FILE'." "red"
    exit 1
fi

if ! grep -q "<!ENTITY md5" "$PLUGIN_FILE"; then
    log "Warning: The md5 entity was not found. Adding a placeholder." "yellow"
    "$SED_CMD" -i.bak '/<!ENTITY version/i\
<!ENTITY md5         "placeholder">
' "$PLUGIN_FILE"
    rm "${PLUGIN_FILE}.bak"
fi

FILE_NAME="$NAME-$VERSION.txz"
PACKAGE_DIR="$SOURCE_DIR/$NAME"

if [[ ! -d "$PACKAGE_DIR" || -z "$(ls -A "$PACKAGE_DIR")" ]]; then
    log "Error: Source directory '$PACKAGE_DIR' does not exist or is empty." "red"
    exit 1
fi

log "================================================" "blue"
log "     Building Unraid Plugin Package"
log "================================================" "blue"
log "Plugin:  $PLUGIN_FILE"
log "Name:    $NAME"
log "Source:  $PACKAGE_DIR"
log "================================================" "blue"

mkdir -p "$ARCHIVE_DIR"
readonly OUTPUT_FILE="$(realpath "$ARCHIVE_DIR")/$FILE_NAME"

(
    cd "$PACKAGE_DIR"
    log "\nSetting file permissions..."
    # Ensure all text files have Unix line endings
    find usr -type f -exec dos2unix {} \;
    # Set correct permissions for directories and files
    find usr -type d -exec chmod 755 {} \;
    find usr -type f -exec chmod 644 {} \;

    log "Creating archive: $FILE_NAME..."
    "$TAR_CMD" -cJf "$OUTPUT_FILE" --owner=0 --group=0 usr/
)

log "Verifying package..."
if [[ -f "$OUTPUT_FILE" ]]; then
    hash=$($MD5_CMD "$OUTPUT_FILE" | cut -f 1 -d " ")
    log "Packaged successfully! MD5: $hash" "green"
    log "Updating plugin info file..."
    "$SED_CMD" -i.bak -e "/<!ENTITY md5/s/\".*\"/\"$hash\"/" \
                       -e "/<!ENTITY version/s/\".*\"/\"$VERSION\"/" \
                       -e "/<!ENTITY branch/s/\".*\"/\"$BRANCH\"/" \
                       "$PLUGIN_FILE"
    rm "${PLUGIN_FILE}.bak"
else
    log "Error: Failed to build package!" "red"
    exit 1
fi

log "\nDone." "green"
