#!/usr/bin/env bash
#
# Liste les adresses que la prison Apache aurait bannies si elle avait ete
# installee plus tot.
#
# fail2ban ne relit jamais un journal en arriere, et un `findtime` d'une heure
# ecarterait de toute facon des lignes vieilles de plusieurs jours : il verrait
# les correspondances sans bannir personne. Le rattrapage est donc forcement
# manuel, et ce script en fabrique la liste.
#
# Le motif n'est PAS recopie ici : il est lu dans le filtre installe, pour que
# la liste ne puisse pas diverger de ce que la prison attrape reellement.
#
# Usage :
#   scripts/fail2ban-backfill.sh <filtre.conf> <journal...>
#
#   scripts/fail2ban-backfill.sh /etc/fail2ban/filter.d/dolinews-apache-probe.conf \
#       /srv/webs/dolinews.com/logs/access.log* > /tmp/scanners.txt
#
# Sortie standard : une adresse par ligne, rien d'autre, pour un enchainement
# direct. Sortie d'erreur : le decompte, pour la lecture humaine.
#
#   xargs -a /tmp/scanners.txt -n1 -r fail2ban-client set dolinews-apache-probe banip
#
# Les journaux .gz sont acceptes tels quels.

set -euo pipefail

if [ "$#" -lt 2 ]; then
    echo "usage: $(basename "$0") <filtre.conf> <journal...>" >&2
    exit 2
fi

filter=$1
shift

if [ ! -r "$filter" ]; then
    echo "$(basename "$0"): filtre illisible: $filter" >&2
    exit 1
fi

# Le lookahead negatif du motif (.well-known) impose PCRE. Un grep sans -P
# accepterait l'option et ne trouverait rien, ce qui se lirait comme "aucun
# scanner" au lieu de "outil inadapte".
if ! printf 'x\n' | grep -qP 'x' 2>/dev/null; then
    echo "$(basename "$0"): ce grep ne connait pas -P (PCRE), installez GNU grep" >&2
    exit 1
fi

probe=$(sed -n 's/^probe[[:space:]]*=[[:space:]]*//p' "$filter" | head -n1)
failregex=$(sed -n 's/^failregex[[:space:]]*=[[:space:]]*//p' "$filter" | head -n1)

if [ -z "$probe" ] || [ -z "$failregex" ]; then
    echo "$(basename "$0"): ni probe ni failregex dans $filter" >&2
    exit 1
fi

# fail2ban substitue ses definitions, puis <HOST>. Ici l'adresse est ce qu'on
# veut extraire et non verifier : elle reste en tete, le reste du motif passe
# en assertion avant pour que -o ne rende que l'adresse.
rest=${failregex#^<HOST>}
rest=${rest//<probe>/$probe}
pattern="^\\S+(?=${rest})"

gzip -dcf -- "$@" \
    | grep -oP "$pattern" \
    | sort \
    | uniq -c \
    | sort -rn \
    | while read -r count ip; do
        printf '%s\n' "$ip"
        printf '%-18s %6d sondes\n' "$ip" "$count" >&2
    done
