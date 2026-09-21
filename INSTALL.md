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
  transactionnels dès le premier jour.

Puis :

```bash
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --force        # pose le compte super administrateur
npm ci && npm run build            # build de validation
php artisan storage:link           # disque public des médias
php artisan config:cache && php artisan route:cache
```

## Ordonnanceur

Une entrée cron unique (voir `~/docs/laravel/LARAVEL_CRON.md`) déclenche
les tâches planifiées : moisson des contributeurs, purge des médias
orphelins, contrôle des liens des fiches, relances de revue à trois jours,
annulation automatique des actes de modération non confirmés à sept jours.

```cron
* * * * * cd /chemin/dolinews && php artisan schedule:run >> /dev/null 2>&1
```

## Niveau de journal

Décision à la mise en production (SPEC 11) : la valeur par défaut `debug`
produit des gigaoctets en production. Recommandé :

```dotenv
LOG_LEVEL=warning
```

## Honeypot et frontal

Le piège à scanners est actif par défaut (`HONEYPOT_ENABLED`). En
exposition directe, la sortie locale fail2ban suffit : copier
`deploy/fail2ban/` vers `/etc/fail2ban/` et adapter `logpath` du jail,
plus `deploy/logrotate/honeypot` vers `/etc/logrotate.d/`. Derrière un
frontal, déclarer son adresse dans `TRUSTED_PROXIES` : la garde du
honeypot refuse de signaler le frontal lui-même.

## Vérifications après déploiement

- `php artisan up` ; la page d'accueil répond et les flux `/feeds.xml` et
  `/feeds.json` valident ;
- une sonde factice (`curl https://service.example/.env`) renvoie 404 et
  écrit une ligne `HONEYPOT` dans `storage/logs/honeypot.log` ;
- `php artisan dolinews:harvest-committers` remplit `known_committer_hashes`
  pour chaque dépôt configuré ;
- la première inscription lecteur reçoit son courriel de validation.
