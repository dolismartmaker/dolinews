---
title: "MonModule 2.1 : import des factures fournisseur"
summary: "La version 2.1 importe les factures fournisseur reçues en PDF et les rattache au tiers correspondant. Elle corrige aussi le calcul de TVA sur les lignes remisées."
type: release
version: "2.1.0"
focus: feature_major
project: monmodule
maturity: stable
compat_status: declared
dolibarr_min: 18
dolibarr_max: 22
locale: fr_FR
---

## Ce que la version apporte

L'import lit le PDF déposé, en tire la référence, la date et les montants,
puis crée la facture fournisseur sur le tiers reconnu. Rien n'est validé
automatiquement : le document créé reste en brouillon.

Une image déjà en ligne est reprise telle quelle :

![Écran d'import des factures](https://exemple.test/captures/import.png)

Une image du dépôt, elle, s'écrit en chemin relatif au fichier - elle est
alors déposée sur le service et l'adresse est réécrite :
`![Écran d'import](captures/import.png)`

## Corrections

- la TVA des lignes remisées était calculée sur le montant avant remise ;
- l'export CSV perdait les accents sous Windows.

## Prérequis

Dolibarr 18 ou supérieur, PHP 8.1 ou supérieur, le module Fournisseurs activé.
