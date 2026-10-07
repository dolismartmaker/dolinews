#!/bin/sh
# Checks that the DoliNews announcement describes the version being released.
#
# A module keeps ONE announcement file, rewritten for every release
# (docs/dolinews.md), instead of one file per tag. That loses the safety net
# naming by tag gave for free: with one file per version, a missing file sends
# nothing and says so. With a single file, the file is ALWAYS there: forgetting
# to rewrite it before tagging would resubmit the previous announcement under
# the new number, silently, into a third party's review queue.
#
# This check replaces that safety net: it refuses an announcement whose header
# does not carry the expected version.
#
# Usage: vendor/bin/check-annonce-version.sh <file> <expected version>
#   e.g. vendor/bin/check-annonce-version.sh docs/dolinews.md 2.4.12

set -e

FILE="$1"
EXPECTED="$2"

if [ -z "$FILE" ] || [ -z "$EXPECTED" ]; then
    echo "usage: $0 <file> <expected version>" >&2
    exit 2
fi

if [ ! -f "$FILE" ]; then
    echo "ERROR: $FILE not found." >&2
    exit 2
fi

# First "version:" field found, hence the header one. The value may be
# single-quoted, double-quoted or bare.
FOUND=$(sed -n 's/^version:[[:space:]]*["'\'']\{0,1\}\([^"'\'' ]*\)["'\'']\{0,1\}[[:space:]]*$/\1/p' "$FILE" | head -1)

if [ -z "$FOUND" ]; then
    echo "ERROR: no 'version:' field in the header of $FILE." >&2
    echo "       Expected version: \"$EXPECTED\"" >&2
    exit 1
fi

if [ "$FOUND" != "$EXPECTED" ]; then
    echo "ERROR: $FILE announces version $FOUND, but $EXPECTED is being released." >&2
    echo "       The announcement file is shared by every release: rewrite it for" >&2
    echo "       $EXPECTED before tagging, or the previous announcement would be" >&2
    echo "       submitted again under the new number." >&2
    exit 1
fi

echo "$FILE announces version $FOUND, which matches the release."
