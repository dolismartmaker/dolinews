# DoliNews

DoliNews est un fil d'annonces pour l'écosystème Dolibarr : il dit ce qui a
été annoncé, et quand. Sorties de modules, correctifs, alertes de sécurité,
fins de vie. Il ne dit jamais l'état courant d'un module : ce qui est daté
vit dans le fil, ce qui est persistant vit dans les fiches projet.

La spécification de référence est `docs/SPEC.md` : en cas de divergence,
elle fait autorité sur le présent fichier.

## Fonctionnalités

- comptes lecteurs gratuits, abonnements aux projets et aux éditeurs avec
  filtres propres, flux personnel à jeton révocable ;
- comptes contributeurs qualifiés par preuve de contribution (code à usage
  unique sur l'adresse de commit, ou défi signé GPG), adresses uniquement
  stockées en empreinte poivrée ;
- fiches projet à liens typés, revue a priori des annonces (quorum de trois
  modérateurs, priorité sécurité), quota par seau à jetons et plafond de
  file ;
- traductions suivies avec péremption quand la source est révisée,
  révisions post-publication avec cliché complet de l'état d'origine ;
- API publique v1 (lecture et soumission) à jetons personnels Sanctum,
  flux RSS et JSON génériques sans compte ;
- journal de modération numéroté, confirmation des actes en conflit
  d'intérêts sous sept jours, annulation automatique au-delà.

## Installation

Voir `INSTALL.md`.

## Qualité

`make ci` exécute Pint, PHPStan (niveau 8, sans baseline) et la suite Pest.
La suite couvre le circuit de revue, les quotas, les traductions et
révisions, la modération, les médias, l'API, les flux, le honeypot et les
pages publiques.

## Licence

GNU AGPL v3. La licence porte sur le code du service : les contenus
publiés restent la propriété de leurs auteurs.
