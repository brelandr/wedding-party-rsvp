#!/bin/bash

# Script to publish assets to WordPress.org SVN
# Usage: ./publish-assets.sh

set -e

PLUGIN_DIR="/Users/randy/wordpress-plugins/wedding-party-rsvp"
SVN_REPO="https://plugins.svn.wordpress.org/wedding-party-rsvp"
# Set via environment or here (see https://wordpress.org/plugins/developers/add/ — Passwords tab for SVN)
SVN_USERNAME="${SVN_USERNAME:-${WPR_SVN_USERNAME:-}}"
SVN_PASSWORD="${SVN_PASSWORD:-${WPR_SVN_PASSWORD:-}}"
ASSETS_DIR="$PLUGIN_DIR/assets"
SVN_CHECKOUT_DIR="$PLUGIN_DIR/svn-checkout"

echo "========================================="
echo "Publishing Assets to WordPress.org SVN"
echo "========================================="
echo ""

# Check if assets directory exists
if [ ! -d "$ASSETS_DIR" ]; then
    echo "Error: Assets directory not found at $ASSETS_DIR"
    exit 1
fi

# Prompt for username if not set
if [ -z "$SVN_USERNAME" ]; then
    read -p "Enter your WordPress.org username: " SVN_USERNAME
fi

# Checkout or update SVN repository
if [ -d "$SVN_CHECKOUT_DIR" ]; then
    echo "Updating existing SVN checkout..."
    cd "$SVN_CHECKOUT_DIR"
    svn update
else
    echo "Checking out SVN repository..."
    if [[ -n "$SVN_PASSWORD" ]]; then
        svn checkout "$SVN_REPO" "$SVN_CHECKOUT_DIR" --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive
    else
        svn checkout "$SVN_REPO" "$SVN_CHECKOUT_DIR" --username "$SVN_USERNAME"
    fi
    cd "$SVN_CHECKOUT_DIR"
fi

# Copy assets
echo "Copying assets..."
cp -r "$ASSETS_DIR"/* assets/

# Add any new files
echo "Adding new files to SVN..."
svn add assets/* 2>/dev/null || true

# Check for changes
if svn status | grep -q "^[AM]"; then
    echo ""
    echo "Files to be committed:"
    svn status | grep "^[AM]"
    echo ""
    read -p "Commit these changes? (y/n): " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "Committing changes..."
        if [[ -n "$SVN_PASSWORD" ]]; then
            svn commit -m "Update plugin assets" --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive
        else
            svn commit -m "Update plugin assets" --username "$SVN_USERNAME"
        fi
        echo ""
        echo "✅ Assets published successfully!"
        echo ""
        echo "Verify at: https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/"
    else
        echo "Commit cancelled."
    fi
else
    echo "No changes to commit."
fi
