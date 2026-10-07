#!/bin/sh
# Verifie que l'annonce DoliNews decrit bien la version que l'on publie.
#
# Le depot ne garde qu'UN SEUL fichier d'annonce, reecrit a chaque version
# (docs/dolinews.md), au lieu d'un fichier par tag. On perd du meme coup le
# garde-fou naturel du nommage par tag : avec un fichier par version, un
# fichier absent ne fait rien partir et le dit. Avec un fichier unique, le
# fichier est TOUJOURS la : l'oublier avant de poser le tag resoumettrait
# l'annonce precedente sous le nouveau numero, sans que rien ne le signale,
# et la soumission entre en file de revue chez un tiers.
#
# Ce controle remplace ce garde-fou : il refuse de soumettre une annonce dont
# l'en-tete ne porte pas la version attendue.
#
# Usage : tools/check-annonce-version.sh <fichier> <version attendue>
#   ex. : tools/check-annonce-version.sh docs/dolinews.md 2.4.12

set -e

FILE="$1"
EXPECTED="$2"

if [ -z "$FILE" ] || [ -z "$EXPECTED" ]; then
    echo "usage: $0 <fichier> <version attendue>" >&2
    exit 2
fi

if [ ! -f "$FILE" ]; then
    echo "ERROR: $FILE not found." >&2
    exit 2
fi

# Premier champ "version:" rencontre, donc celui de l'en-tete. La valeur peut
# etre entre guillemets simples ou doubles, ou nue.
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
