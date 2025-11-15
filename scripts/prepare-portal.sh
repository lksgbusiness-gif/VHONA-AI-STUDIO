#!/usr/bin/env bash
set -euo pipefail

function usage() {
  cat <<'USAGE'
Usage: scripts/prepare-portal.sh [--wp-dir /path/to/wordpress]

Builds the portal bundle and (optionally) copies the plugin into the target
WordPress installation so it is ready to activate.

Options:
  --wp-dir PATH   Absolute path to the WordPress root where the plugin should
                  be copied. If omitted, the script only builds the bundle.
  -h, --help      Show this help text.
USAGE
}

WP_DIR=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --wp-dir)
      WP_DIR="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$REPO_ROOT/frontend"
PLUGIN_SRC="$REPO_ROOT/wordpress/wp-content/plugins/client-portal"

if [[ ! -d "$PLUGIN_SRC" ]]; then
  echo "Unable to locate client portal plugin at $PLUGIN_SRC" >&2
  exit 1
fi

echo "Building portal bundle via frontend/scripts/build-portal.js..."
node "$FRONTEND_DIR/scripts/build-portal.js"

echo "Portal assets copied to wordpress/wp-content/plugins/client-portal/build/portal."

if [[ -n "$WP_DIR" ]]; then
  if [[ ! -d "$WP_DIR" ]]; then
    echo "The provided WordPress directory $WP_DIR does not exist" >&2
    exit 1
  fi

  DEST="$WP_DIR/wp-content/plugins/client-portal"
  echo "Syncing plugin to $DEST"
  mkdir -p "$DEST"
  rsync -a --delete "$PLUGIN_SRC/" "$DEST/"
  cat <<EOF2
Plugin synced. Activate it with either:
  - WordPress Admin → Plugins → Activate on "VHONA Client Portal"
  - wp plugin activate client-portal
EOF2
else
  echo "No --wp-dir provided; skipping plugin sync."
fi

cat <<'NEXT'
Next steps:
 1. Log into WordPress and activate the VHONA Client Portal plugin if you have not already.
 2. Visit Client Portal → Settings to finish the setup wizard (storage, branding, integrations).
 3. Create manager/client accounts and run through MANUAL-TESTING.md to verify each module.
NEXT
