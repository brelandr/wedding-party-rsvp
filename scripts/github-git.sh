#!/usr/bin/env bash
# See wedding-party-rsvp-pro/scripts/github-git.sh (same pattern).
# Token only via: export GITHUB_TOKEN="ghp_..." — never commit tokens.
set -euo pipefail
if [[ -z "${GITHUB_TOKEN:-}" ]]; then
	echo "Set GITHUB_TOKEN to your GitHub PAT, or use: gh auth login && git push" >&2
	exit 1
fi
USER="${GITHUB_USER:-brelandr}"
B64=$(printf '%s:%s' "$USER" "$GITHUB_TOKEN" | base64 | tr -d '\n')
exec git -c "http.https://github.com/.extraHeader=Authorization: Basic ${B64}" "$@"
