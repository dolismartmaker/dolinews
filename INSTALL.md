# Installation de DoliNews

## Prérequis

- PHP 8.2 ou supérieur, avec les extensions `gd`, `sqlite3` (ou le pilote
  MySQL/MariaDB choisi), `mbstring`, `xml`, `curl` ;
- Composer 2, Node 20 et npm pour le build de validation ;
- un serveur SSH/SFTP et PHP-FPM derrière un frontal nginx ou Apache ;
- `git` sur le serveur : la vérification des contributeurs moissonne des
  clones locaux des dépôts de référence (aucune API de forge n'est
  appelée) ;
- `gpg` (GnuPG 2.x) si le niveau de vérification fort est proposé.

## Mise en place

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Renseigner dans `.env` :

- `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false` ;
- la connexion de base de données (SQLite suffit au lancement) ;
- `DOLINEWS_COMMITTER_PEPPER` : un secret long et unique, généré par
  exemple par `openssl rand -hex 32`. Ce poivre n'est JAMAIS changé ni
  régénéré : le modifier invaliderait toutes les empreintes d'adresses de
  commits déjà calculées ;
- `DOLINEWS_REFERENCE_REPOS` : chemins des clones git de référence,
  séparés par des virgules ;
- `DOLINEWS_SUPER_ADMIN_EMAIL` et `DOLINEWS_SUPER_ADMIN_PASSWORD` (à
  changer dès la première connexion) ;
- le courriel (`MAIL_*`) : le circuit de revue envoie des messages
  transactionnels dès le premier jour ;
- `OPS_SECURITY_EMAIL` : destinataire du rapport quotidien d'audit des
  dépendances. À défaut, l'adresse d'expédition de l'application est
  utilisée.

Puis :

```bash
touch database/database.sqlite
make migrate
make seed                          # pose le compte super administrateur
make prod                          # npm ci + build de validation
make storage-link                  # disque public des médias
make cache                         # config, événements, routes, vues
```

Le `Makefile` suit la convention du parc : `make help` liste les cibles,
`make env` montre l'environnement détecté. Les valeurs propres au serveur
(comptes, chemin de node) vont dans un `Makefile.local` non versionné ;
sur le serveur de production, le déploiement est lancé par `ericsadmin`
et le site servi par `www-data`. Voir `docs/DEPLOIEMENT.md`.

## Fichiers système : ordonnanceur, worker, rotation, fail2ban

Quatre fichiers vivent hors du dépôt, dans `/etc`. Aucun ne se copie à la
main : `deploy/` contient des **gabarits** portant `{{APP_PATH}}`,
`{{PHP}}`, `{{USER}}`, `{{LOG_DIR}}`, `{{APP_SLUG}}`, que les commandes de
`caprel/laravel-ops` résolvent avec les valeurs du déploiement courant.

```bash
make cron          # /etc/cron.d/dolinews, l'ordonnanceur
make supervisor    # le worker de file, puis reread/update/restart
make logrotate     # rotation de cron.log et honeypot.log
make fail2ban      # piège à scanners, en exposition directe uniquement
```

Chaque cible a son pendant `make <cible>-print`, qui affiche le rendu sans
rien écrire. Ne jamais retoucher le fichier produit dans `/etc` : le
déploiement suivant l'écrase. Modifier le gabarit de `deploy/`, puis
relancer la cible.

**L'ordonnanceur** déclenche les tâches planifiées : moisson des
contributeurs, purge des médias orphelins, contrôle des liens des fiches,
relances de revue à trois jours, annulation automatique des actes de
modération non confirmés à sept jours, et l'audit quotidien des
dépendances à 06:00 (`OPS_SECURITY_EMAIL`, voir `docs/SECURITY.md`).
L'entrée est `* * * * *`, sans
exception : cron ne fait que réveiller Laravel, qui décide ensuite à la
minute près des tâches dues. Une entrée `*/5` supprimerait en silence
toute tâche dont l'horaire n'est pas un multiple de cinq.

**Le worker** est indispensable : les courriels du circuit de revue et le
signalement honeypot sont en file (`QUEUE_CONNECTION=database`). Sans lui,
les lignes s'empilent dans la table `jobs`, rien ne part, et le journal
applicatif reste vide - ce qui se lit comme "rien à signaler" plutôt que
"rien n'est parti".

Un seul ordonnanceur, jamais deux : une entrée cron `schedule:run` **et**
un programme Supervisor `schedule:work` exécuteraient chaque tâche en
double. Le gabarit `deploy/supervisor/worker.conf` ne déclare que le
worker, et dit en commentaire comment basculer si l'on veut l'autre
topologie.

Détail et pièges : `~/docs/laravel/LARAVEL_CRON.md` et
`~/docs/laravel/LARAVEL_QUEUE_SUPERVISOR.md`.

## Niveau de journal

Décision à la mise en production (SPEC 11) : la valeur par défaut `debug`
produit des gigaoctets en production. Recommandé :

```dotenv
LOG_LEVEL=warning
```

## Honeypot et frontal

Le piège à scanners est actif par défaut (`HONEYPOT_ENABLED`). En
exposition directe, la sortie locale fail2ban suffit : `make fail2ban` et
`make logrotate` posent le filtre, les deux prisons et la rotation.
Derrière un frontal, ne pas les installer - un bannissement local y jette
des paquets qui ne viennent jamais du scanner - et déclarer l'adresse du
frontal dans `TRUSTED_PROXIES` : la garde du honeypot refuse de signaler
le frontal lui-même.

## Vérifications après déploiement

- `make doctor` ne remonte aucun `FAIL`. Il répond à la question que
  `make ci` ne pose pas : ce qui tourne sur ce serveur est-il complet ?
  Environnement, dépendances, base, file, ordonnanceur, worker, caches,
  assets. Chaque ligne en défaut porte la commande qui la répare, et le
  code de retour est non nul sur `FAIL`, donc la commande se branche
  telle quelle sur une supervision externe (`make doctor-json`) ;
- `make queue-status` : la file ne s'accumule pas. C'est le seul contrôle
  qui attrape aussi le worker qui tourne mais reste bloqué ;
- `php artisan up` ; la page d'accueil répond et les flux `/feeds.xml` et
  `/feeds.json` valident ;
- une sonde factice (`curl https://service.example/.env`) renvoie 404 et
  écrit une ligne `HONEYPOT` dans `storage/logs/honeypot.log` ;
- `php artisan dolinews:harvest-committers` remplit `known_committer_hashes`
  pour chaque dépôt configuré, ou `dolinews:import-committers <fichier>`
  si le serveur n'héberge pas de clone (voir `docs/EXPLOITATION.md`) ;
- la première inscription lecteur reçoit son courriel de validation.
