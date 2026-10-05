# Afficher le fil DoliNews dans un site WordPress

`dolinews-feed.php` affiche une section du fil d'annonces dans un site WordPress,
filtrée par éditeur, par projet, par version de Dolibarr ou par nature de
publication. Un seul fichier, aucune dépendance : pas de Composer, pas de plugin
tiers, pas de JavaScript, pas d'étape de construction.

La lecture du fil est gratuite et sans compte : aucun jeton n'est nécessaire.

## Installation

Deux voies, l'une ou l'autre, **jamais les deux** - charger le fichier deux fois
redéclare ses fonctions et casse le site.

1. **En extension.** Copier `dolinews-feed.php` dans
   `wp-content/plugins/dolinews-feed/`, puis activer "DoliNews Feed" dans
   Extensions.
2. **Dans le thème.** Coller le contenu du fichier à la fin du `functions.php`
   du thème enfant, en retirant l'en-tête de commentaire d'extension.

Pour viser une autre instance du service que `https://dolinews.com`, ajouter
dans `wp-config.php` :

```php
define('DOLINEWS_FEED_BASE', 'https://mon-instance.example');
```

## Usage

Le code court `[dolinews]` s'écrit dans n'importe quel bloc "Code court", dans
un widget de texte, ou dans un gabarit via `dolinews_feed(array(...))`.

```
[dolinews editor="mon-editeur" limit="5"]
[dolinews project="mon-module" limit="3" layout="cards"]
[dolinews focus="security" dolibarr="22" title="Correctifs de sécurité"]
```

| Attribut | Défaut | Rôle |
|----------|--------|------|
| `editor` | - | slug de l'éditeur, celui de l'adresse `/editeurs/<slug>` |
| `project` | - | slug du projet, celui de l'adresse `/projets/<slug>` |
| `focus` | - | `security`, `bugfix_major`, `feature_minor`, `compat`, `eol`... |
| `dolibarr` | - | version majeure que les annonces concernent (`22`) |
| `maturity` | stable seule | liste séparée par des virgules : `beta,rc` |
| `locale` | langue du service | locale de contenu lue : `fr_FR`, `es_ES`... |
| `q` | - | recherche libre, comme le champ du fil |
| `limit` | `5` | nombre d'annonces, de 1 à 50 |
| `layout` | `list` | `list` ou `cards` |
| `summary` | `yes` | afficher le résumé de chaque annonce |
| `cache` | `900` | durée du cache local, en secondes (minimum 60) |
| `base` | `DOLINEWS_FEED_BASE` | adresse du service |
| `title` | - | titre affiché au-dessus du bloc |
| `link_text` | libellé du flux | libellé du lien vers l'annonce |
| `empty_text` | libellé du flux | texte affiché quand rien ne correspond |

Le slug de l'éditeur ou du projet se lit dans l'adresse de sa fiche sur le
service.

## Ce que le bloc affiche, et pourquoi

Les libellés des pastilles - plage Dolibarr, maturité et son ancienneté, nature
de la publication, langue de l'annonce - viennent du flux lui-même, déjà écrits
dans la langue demandée. Les deux textes d'habillage, le lien vers l'annonce et
la phrase affichée quand rien ne correspond, en viennent aussi : réglez `locale`
et le bloc entier parle cette langue, sans fichier de traduction à installer.
`link_text` et `empty_text` restent là pour imposer vos propres mots.

Trois règles du service sont tenues ici, et ce sont elles qui justifient ce
fichier plutôt qu'un widget RSS générique :

- **seules les annonces `stable` sont listées** tant qu'une autre maturité n'est
  pas nommée : un site en production n'a que faire d'un fil de versions d'essai.
  Il n'existe volontairement aucune valeur "tout inclure" ;
- **une maturité n'est jamais affichée sans son ancienneté** : personne ne
  revient dire qu'une version est sortie de sa phase d'essai ;
- **rien n'y affirme qu'un module est compatible** avec une version de Dolibarr.
  Le fil dit ce qui a été annoncé, et quand.

Le bloc porte le nom de l'éditeur, un lien vers l'annonce et la licence sous
laquelle elle est publiée (CC BY-SA 4.0). Les retirer rend la copie non
autorisée.

## Fiabilité

Chaque combinaison de filtres est mise en cache localement par deux transients :
le court sert la page, le long garde la dernière réponse connue pendant une
semaine. Un service lent ou injoignable affiche donc les annonces de l'heure
précédente plutôt qu'un bloc vide, et la raison de l'échec part dans le journal
d'erreurs PHP (`error_log`). Cache froid et service injoignable : le bloc
n'affiche rien du tout - une page d'un site tiers n'est pas l'endroit où
afficher notre panne.

Aucune image distante n'est chargée, et le bloc n'ajoute ni script, ni cookie,
ni appel tiers côté navigateur : tout se passe sur le serveur WordPress.

## Les autres voies, pour mémoire

- **flux RSS** `https://dolinews.com/feeds.xml?editor=<slug>`, avec les mêmes
  filtres, lisible par le bloc RSS natif de WordPress ;
- **flux JSON** `https://dolinews.com/feeds.json?editor=<slug>`, celui que cette
  extension lit. Chaque entrée porte un membre `_dolinews` avec les valeurs
  brutes et leurs libellés ;
- **API** `https://dolinews.com/api/v1/articles?editor=<slug>`, pour un client
  qui veut les champs structurés et la pagination. Elle garde un filtre de
  langue strict : une annonce non traduite dans la langue demandée n'y figure
  pas, là où le flux sert sa version d'origine. C'est le flux qu'il faut pour
  afficher, l'API pour traiter.

## Licence

`dolinews-feed.php` est publié sous GNU GPL v3 ou ultérieure, compatible avec
WordPress. Le reste du service est sous GNU AGPL v3.
