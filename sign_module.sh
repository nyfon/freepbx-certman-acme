#!/usr/bin/env bash
# =============================================================================
# FreePBX Local Module Signing — Developer Build Tool
# =============================================================================
#
# Run this ONCE before packaging/distributing the module. It pre-signs the
# module so that install.php can deploy the signature automatically — no
# signing is required on the target FreePBX server.
#
# What it creates inside the module directory:
#   signing/certmanacme.sig   — GPG clearsigned INI with file hashes
#   signing/signing-key.pub   — Exported GPG public key
#   module.sig                — GPG clearsigned INI referencing the secure sig
#
# install.php then copies these to the right places at install time.
#
# Usage:
#   ./sign_module.sh [GPG_KEY_ID]
#
#   GPG_KEY_ID  — GPG key fingerprint or email (default: first secret key)
#
# First-time setup:
#   gpg --full-generate-key   # RSA 4096, no expiry recommended
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MODULE_DIR="$SCRIPT_DIR"
MODULE_RAW_NAME="certmanacme"
SIGNING_DIR="${MODULE_DIR}/signing"

# Files/patterns to exclude from hashing (relative to module dir)
EXCLUDE_PATTERNS=(
    "module.sig"
    "sign_module.sh"
    "signing"
    "signing/*"
    ".git"
    ".git/*"
    ".gitignore"
    ".gitattributes"
    "*.pyc"
    "__pycache__"
    "__pycache__/*"
)

# --- Resolve GPG key ---------------------------------------------------------

GPG_KEY="${1:-}"

if [[ -z "$GPG_KEY" ]]; then
    GPG_KEY=$(gpg --list-secret-keys --with-colons 2>/dev/null \
        | awk -F: '/^sec/{found=1} found && /^fpr/{print $10; exit}')
    if [[ -z "$GPG_KEY" ]]; then
        echo "ERROR: No GPG secret key found. Generate one first:" >&2
        echo "  gpg --full-generate-key" >&2
        exit 1
    fi
fi

if ! gpg --list-secret-keys "$GPG_KEY" &>/dev/null; then
    echo "ERROR: GPG key not found: $GPG_KEY" >&2
    exit 1
fi

SHORT_KEY=$(gpg --list-secret-keys --with-colons "$GPG_KEY" 2>/dev/null \
    | awk -F: '/^fpr/{print substr($10, length($10)-15); exit}')

echo "Module:      $MODULE_RAW_NAME"
echo "Module dir:  $MODULE_DIR"
echo "Signing key: $GPG_KEY (short: $SHORT_KEY)"
echo ""

# --- Prepare signing directory -----------------------------------------------

mkdir -p "$SIGNING_DIR"

# --- Export public key -------------------------------------------------------

echo "Exporting public key..."
gpg --batch --yes --export --armor "$GPG_KEY" > "${SIGNING_DIR}/signing-key.pub"
echo "  Written: signing/signing-key.pub"

# --- Generate file hashes ----------------------------------------------------

echo "Generating SHA256 hashes..."

HASHES=""
FILE_COUNT=0

while IFS= read -r -d '' file; do
    relpath="${file#${MODULE_DIR}/}"

    # Check exclusions
    excluded=false
    for pattern in "${EXCLUDE_PATTERNS[@]}"; do
        # shellcheck disable=SC2254
        case "$relpath" in
            $pattern) excluded=true; break ;;
        esac
    done
    [[ "$excluded" == "true" ]] && continue

    hash=$(sha256sum "$file" | awk '{print $1}')
    HASHES="${HASHES}${relpath} = \"${hash}\"
"
    FILE_COUNT=$((FILE_COUNT + 1))
done < <(find "$MODULE_DIR" -type f -print0 | sort -z)

echo "  Hashed $FILE_COUNT files"

# --- Create local secure signature -------------------------------------------

echo "Creating secure signature (signing/certmanacme.sig)..."

LOCAL_SIG_CONTENT="[config]
version = 2
type = local
signedwith = ${SHORT_KEY}

[hashes]
${HASHES}"

TMPFILE=$(mktemp)
echo "$LOCAL_SIG_CONTENT" > "$TMPFILE"

gpg --batch --yes --default-key "$GPG_KEY" \
    --clearsign --output "${SIGNING_DIR}/${MODULE_RAW_NAME}.sig" "$TMPFILE"
rm -f "$TMPFILE"

echo "  Written: signing/${MODULE_RAW_NAME}.sig"

# --- Create module.sig -------------------------------------------------------

echo "Creating module.sig..."

LOCAL_SIG_HASH=$(sha256sum "${SIGNING_DIR}/${MODULE_RAW_NAME}.sig" | awk '{print $1}')

MODULE_SIG_CONTENT="[config]
version = 2
type = local
signedwith = ${SHORT_KEY}

[hashes]
${MODULE_RAW_NAME}.sig = \"${LOCAL_SIG_HASH}\"
"

TMPFILE=$(mktemp)
echo "$MODULE_SIG_CONTENT" > "$TMPFILE"

gpg --batch --yes --default-key "$GPG_KEY" \
    --clearsign --output "${MODULE_DIR}/module.sig" "$TMPFILE"
rm -f "$TMPFILE"

echo "  Written: module.sig"

# --- Verify ------------------------------------------------------------------

echo ""
echo "Verifying..."
gpg --verify "${MODULE_DIR}/module.sig" &>/dev/null 2>&1 \
    && echo "  module.sig: OK" \
    || echo "  module.sig: FAILED" >&2

gpg --verify "${SIGNING_DIR}/${MODULE_RAW_NAME}.sig" &>/dev/null 2>&1 \
    && echo "  signing/${MODULE_RAW_NAME}.sig: OK" \
    || echo "  signing/${MODULE_RAW_NAME}.sig: FAILED" >&2

echo ""
echo "=== Done. Module is pre-signed and ready to distribute. ==="
echo ""
echo "The install.php will handle deploying the signature and GPG key"
echo "to the target FreePBX server automatically."
