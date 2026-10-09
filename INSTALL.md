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
- `SESSION_SECURE_COOKIE=true` : obligatoire dès que le site est servi en
  https. Sans cette valeur, le cookie de session part en clair sur le
  moindre accès HTTP résiduel, et il suffit d'un seul pour le capter ;
- la connexion de base de données (SQLite suffit au lancement) ;
- `DOLINEWS_COMMITTER_PEPPER` : un secret long et unique, généré par
  exemple par `openssl rand -hex 32`. Ce poivre n'est JAMAIS changé ni
  régénéré : le modifier invaliderait toutes les empreintes d'adresses de
  commits déjà calculées ;
- `DOLINEWS_REFERENCE_REPOS` : chemins des clones git de référence,
  séparés par des virgules ;
- `DOLINEWS_SUPER_ADMIN_EMAIL` et `DOLINEWS_SUPER_ADMIN_PASSWORD`. Ce mot
  de passe est celui du fichier de configuration, donc connu de qui
  déploie : le compte est posé avec un marqueur de changement obligatoire
  et n'atteint aucun écran avant d'en avoir choisi un autre ;
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

`make storage-link` fait partie de `make all` et n'est pas réservé à
l'installation : un déploiement par `rsync` recrée `public/storage` en lien
pointant vers le chemin de la machine **source**, qui n'existe pas ici. Apache
répond alors 403 - et non 404 - sur toutes les images de tous les articles,
sans que rien d'autre ne le signale. La cible échoue si le lien ne résout pas.

Le `Makefile` suit la convention du parc : `make help` liste les cibles,
`make env` montre l'environnement détecté. Les valeurs propres au serveur
(comptes, chemin de node) vont dans un `Makefile.local` non versionné ;
sur le serveur de production, le déploiement est lancé par `ericsadmin`
et le site servi par `www-data`. Voir `docs/DEPLOIEMENT.md`.

## Transfert vers le serveur

`make rsyncprod` est la seule cible destinée à être lancée depuis le poste de
développement. La destination est une valeur de site, donc elle va dans
`Makefile.local` :

```make
RSYNC_TARGET := ericsadmin@zz:/srv/webs/dolinews.com/app/
```

```bash
make rsyncprod-dry     # montre ce qui partirait, n'écrit rien
make rsyncprod
```

Les exclusions sont dans `deploy/rsync-exclude.txt`, versionnées avec leur
motif, et la cible refuse de s'exécuter si le fichier manque. Quatre lignes y
réparent des incidents constatés :

- `/public/storage` : `rsync -a` implique `-l` et recopie le lien tel quel. Il
  arrive sur le serveur en pointant vers un chemin du poste de développement,
  donc mort, et Apache répond 403 sur toutes les images. Le serveur refait le
  sien avec `make storage-link` ;
- `/storage` : les journaux et les médias du serveur, écrasés par ceux d'ici.
  Un `honeypot.log` importé fait perdre à fail2ban sa position dans le
  fichier, sans que rien ne le signale : la prison reste active et ne compte
  plus rien ;
- `/bootstrap/cache` : la configuration compilée du poste de développement,
  servie en production entre le transfert et le `make cache` qui la
  reconstruit ;
- `/database/*.sqlite` : la base de développement par-dessus celle du serveur.

`--delete` n'est pas posé par défaut - un premier transfert ne peut donc rien
supprimer. Une fois les exclusions vérifiées à blanc, l'ajouter est
préférable : sans lui, un fichier retiré du dépôt reste indéfiniment en
production.

```bash
make rsyncprod RSYNC_DELETE=--delete
```

Puis, sur le serveur : `make composer && make migrate && make storage-link &&
make cache`.

## Fichiers système : ordonnanceur, worker, rotation, fail2ban

Ces fichiers vivent hors du dépôt, dans `/etc`. Aucun ne se copie à la
main : `deploy/` contient des **gabarits** portant `{{APP_PATH}}`,
`{{PHP}}`, `{{USER}}`, `{{LOG_DIR}}`, `{{APP_SLUG}}`, `{{ACCESS_LOG}}`, que les
commandes de `caprel/laravel-ops` résolvent avec les valeurs du déploiement
courant.

```bash
make cron              # /etc/cron.d/dolinews, l'ordonnanceur
make supervisor        # le worker de file, puis reread/update/restart
make logrotate         # rotation de cron.log et honeypot.log
make fail2ban          # piège à scanners, en exposition directe uniquement
make fail2ban-apache   # sondes vues par Apache et jamais par PHP
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

Détail et pièges : `capdoc:LARAVEL_CRON.md` et
`capdoc:LARAVEL_QUEUE_SUPERVISOR.md`.

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

**Le piège applicatif ne voit que ce qui atteint PHP**, et c'est la moitié
d'un scan. Une demande de `/.env`, `/.git/config` ou `/.aws/credentials` est
refusée par Apache lui-même : elle n'écrit rien dans `honeypot.log` et les
prisons ci-dessus ne peuvent pas la bannir. Relevé sur une semaine de ce
site : 554 requêtes de ce type venant de 20 adresses, dont quatre que
l'application n'a jamais vues.

`make fail2ban-apache` pose la prison qui lit le journal d'accès du vhost et
bannit dès la première demande d'un chemin dont un segment commence par un
point, `.well-known/` excepté - sans jamais regarder le code de retour, parce
qu'un 403 ne prouve rien (le jour où le lien `public/storage` était mort, le
site a répondu 403 à une centaine de requêtes d'images de vrais lecteurs).

Le journal à surveiller est celui **du vhost**, rarement celui de la
distribution. Le lire dans `apachectl -S`, puis le déclarer :

```dotenv
OPS_FAIL2BAN_APACHE_ACCESS_LOG=/var/log/apache2/dolinews-access.log
```

La commande refuse d'écrire si le fichier n'existe pas : fail2ban, lui,
accepterait la prison sans un mot, se déclarerait active et ne bannirait
personne.

**Rattrapage de l'historique.** fail2ban ne relit jamais un journal en
arrière, et son `findtime` d'une heure écarterait de toute façon des lignes
vieilles de plusieurs jours : les scanners déjà passés ne seront jamais
bannis tout seuls. `scripts/fail2ban-backfill.sh` en dresse la liste, en
lisant le motif dans le filtre installé plutôt qu'en le recopiant :

```bash
scripts/fail2ban-backfill.sh /etc/fail2ban/filter.d/dolinews-apache-probe.conf \
    /srv/webs/dolinews.com/logs/access.log* > /tmp/scanners.txt
xargs -a /tmp/scanners.txt -n1 -r fail2ban-client set dolinews-apache-probe banip
```

Il n'écrit qu'une adresse par ligne sur la sortie standard, le décompte allant
sur la sortie d'erreur : rien n'est banni sans que la commande soit tapée.
S'en tenir aux adresses, jamais aux plages ni aux systèmes autonomes - les
agrégateurs de flux (Feedly, Inoreader) vivent sur les mêmes hébergeurs que
ces scanners, et les couper ne se remarquerait pas : leurs lecteurs
disparaissent, sans un mot.

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
- une sonde factice renvoie 404 et écrit une ligne `HONEYPOT` dans
  `storage/logs/honeypot.log`. La viser sur un chemin qu'Apache laisse
  passer : `curl https://service.example/api/.env`, et non `/.env`, que le
  serveur refuse lui-même en 403 sans rien écrire - c'est le trou que
  `make fail2ban-apache` comble ;
- les deux pièges comptent : `fail2ban-client status dolinews-honeypot-instant`
  et `fail2ban-client status dolinews-apache-probe`. Une prison active dont
  le compteur reste à zéro pendant qu'un scan passe dans les journaux
  désigne un `logpath` qui ne correspond à aucun fichier écrit ;
- les images des articles s'affichent : `ls -l public/storage` doit montrer
  un lien qui résout. Après un `rsync`, il pointe vers la machine source et
  Apache répond 403 sur chaque média ;
- `php artisan dolinews:harvest-committers` remplit `known_committer_hashes`
  pour chaque dépôt configuré, ou `dolinews:import-committers <fichier>`
  si le serveur n'héberge pas de clone (voir `docs/EXPLOITATION.md`) ;
- la première inscription lecteur reçoit son courriel de validation.
