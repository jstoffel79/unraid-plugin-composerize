#!/bin/bash

# A modernized script to build Unraid plugin packages.
# It automatically packages the source, calculates the MD5 hash,
# and updates the .plg file with the new version and hash.

# --- Configuration ---
# Exit immediately if a command exits with a non-zero status.
# Treat unset variables as an error when substituting.
# Exit with a non-zero status if any command in a pipeline fails.
set -euo pipefail

# --- Globals ---
readonly ARCHIVE_DIR="./archive"
readonly SOURCE_DIR="./source"
readonly SCRIPT_NAME=$(basename "$0")

# --- Functions ---

# Print a usage message and exit.
usage() {
    echo "Usage: $SCRIPT_NAME <plugin_file.plg> [branch] [version]"
    echo "  <plugin_file.plg>  : The .plg file for the plugin."
    echo "  [branch]           : (Optional) The git branch to set. Defaults to 'main'."
    echo "  [version]          : (Optional) The version to set. Defaults to current date (YYYY.MM.DD)."
    exit 1
}

# Log a message to the console.
# Arguments:
#   $1: The message to log.
#   $2: The color of the message (optional).
log() {
    local color_reset='\033[0m'
    local color_green='\033[0;32m'
    local color_red='\033[0;31m'
    local color_yellow='\033[0;33m'
    local color_blue='\033[0;34m'
    local color_code

    case "${2:-}" in
        green)  color_code="$color_green" ;;
        red)    color_code="$color_red" ;;
        yellow) color_code="$color_yellow" ;;
        blue)   color_code="$color_blue" ;;
        *)      color_code="" ;;
    esac

    printf "${color_code}%s${color_reset}\n" "$1"
}

# --- Main Script ---

# Argument validation
if [[ "$#" -lt 1 || "$#" -gt 3 || "${1##*.}" != "plg" ]]; then
    usage
fi

PLUGIN_FILE="$1"
BRANCH="${2:-main}"
VERSION="${3:-$(date +%Y.%m.%d)}" # <-- This line is updated

# Detect OS and set command prefixes for cross-compatibility (macOS vs Linux)
SED_CMD="sed"
TAR_CMD="tar"
MD5_CMD="md5sum"
if [[ "$(uname)" == "Darwin" ]]; then
    SED_CMD="gsed" # Requires gnu-sed on macOS: `brew install gnu-sed`
    TAR_CMD="gtar" # Requires gnu-tar on macOS: `brew install gnu-tar`
    MD5_CMD="md5 -r"
fi

# Check for required commands
for cmd in "$SED_CMD" "$TAR_CMD" "dos2unix"; do
    if ! command -v "$cmd" &> /dev/null; then
        log "Error: Required command '$cmd' is not installed. Please install it to continue." "red"
        exit 1
    fi
done

# Extract the plugin name from the .plg file using sed
NAME=$("$SED_CMD" -n 's/<!ENTITY[[:space:]]\+name[[:space:]]\+"\(.*\)">/\1/p' "$PLUGIN_FILE")
if [[ -z "$NAME" ]]; then
    log "Error: Could not extract plugin name from '$PLUGIN_FILE'." "red"
    exit 1
fi

# Ensure the plugin file has a placeholder for the md5 hash.
# Unraid's installer can fail if this entity is missing completely.
if ! grep -q "<!ENTITY md5" "$PLUGIN_FILE"; then
    log "Warning: The md5 entity was not found. Adding a placeholder to '$PLUGIN_FILE'." "yellow"
    # Insert a placeholder md5 entity before the version entity for consistency.
    "$SED_CMD" -i.bak '/<!ENTITY version/i\
<!ENTITY md5         "placeholder">
' "$PLUGIN_FILE"
    rm "${PLUGIN_FILE}.bak"
fi

FILE_NAME="$NAME-$VERSION.txz"
PACKAGE_DIR="$SOURCE_DIR/$NAME"

# Validate that the source directory exists and is not empty
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
log "Archive: $ARCHIVE_DIR"
log "Version: $VERSION"
log "Branch:  $BRANCH"
log "================================================" "blue"

# Create archive directory and define the full path for the output file
mkdir -p "$ARCHIVE_DIR"
readonly OUTPUT_FILE="$(realpath "$ARCHIVE_DIR")/$FILE_NAME"

# Temporarily change to the package directory to create the archive
(
    cd "$PACKAGE_DIR"
    log "\nSetting file permissions..."
    # Ensure all text files have Unix line endings
    find usr -type f -exec dos2unix {} \;
    # Set standard permissions for plugin files
    chmod -R 755 usr/

    log "Creating archive: $FILE_NAME..."
    # Create a compressed tarball with root ownership
    "$TAR_CMD" -cJf "$OUTPUT_FILE" --owner=0 --group=0 usr/
)

log "Verifying package..."
if [[ -f "$OUTPUT_FILE" ]]; then
    # Calculate MD5 hash
    hash=$($MD5_CMD "$OUTPUT_FILE" | cut -f 1 -d " ")

    if [[ -z "$hash" ]]; then
        log "Error: Could not calculate MD5 hash for the archive." "red"
        exit 1
    fi

    log "Packaged successfully! MD5: $hash" "green"

    log "Updating plugin info file..."
    # Use sed to update the .plg file in-place with the new info
    "$SED_CMD" -i.bak -e "/<!ENTITY md5/s/\".*\"/\"$hash\"/" \
                       -e "/<!ENTITY version/s/\".*\"/\"$VERSION\"/" \
                       -e "/<!ENTITY branch/s/\".*\"/\"$BRANCH\"/" \
                       "$PLUGIN_FILE"
    rm "${PLUGIN_FILE}.bak" # Clean up the backup file created by sed
else
    log "Error: Failed to build package!" "red"
    exit 1
fi

log "\nDone." "green"
