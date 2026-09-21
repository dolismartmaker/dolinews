<?php

declare(strict_types=1);

/**
 * Submits the Capmail project sheet and its two release articles to a
 * DoliNews instance through the public API (SPEC 5.2).
 *
 * The script only uses /api/v1: it never touches the database. It needs
 * a personal token of an account that is a CONTRIBUTOR and a member of
 * the editor publishing Capmail. A token grants the right to submit,
 * never to publish: the four articles land in the review queue, and a
 * moderator (or the super admin during the bootstrap phase) publishes
 * them from the back office.
 *
 * Not idempotent on articles: re-running it submits them again. The
 * project sheet, its links and its translation are reused when they
 * already exist.
 *
 * Usage:
 *   php scripts/publish-capmail-articles.php [--dry-run]
 */

require_once __DIR__.'/lib/dolinews-client.php';

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

/** Base URL of the API, without trailing slash. */
const API_BASE = 'https://dolinews.com/api/v1';

/** Personal token of the contributor account (Authorization: Bearer). */
const API_TOKEN = '';

/**
 * Slug of the editor to publish for. Empty means: take the only editor
 * the account belongs to, and refuse to guess when there are several.
 */
const EDITOR_SLUG = '';

/**
 * Editor created when the account owns none yet. The name is public:
 * it signs every announcement. The contact address is not published,
 * the review team writes to it; leave it empty and the script stops
 * rather than inventing one.
 */
const EDITOR_NAME = 'CAP-REL';
const EDITOR_CONTACT_EMAIL = '';
const EDITOR_WEBSITE = 'https://www.cap-rel.fr';
const EDITOR_DESCRIPTION = '';

/** Directory holding the Capmail user documentation screenshots. */
const SCREENSHOT_DIR = '/home/groups/devs/code/modules-dolibarr/capmail/docs/users/screenshots';

/** Typed links of the sheet (SPEC 4.2). URL shorteners are refused. */
const PROJECT_LINKS = [
    ['type' => 'repo', 'url' => 'https://inligit.fr/cap-rel/dolibarr/plugin-capmail', 'label' => 'Dépôt git'],
    ['type' => 'shop', 'url' => 'https://shop.cap-rel.fr/product/MOD-Dolibarr-capMail', 'label' => 'Boutique CAP-REL'],
    ['type' => 'support', 'url' => 'https://sav.cap-rel.fr/', 'label' => 'Support'],
];

/**
 * Screenshots deposited before the articles, keyed by the placeholder
 * the bodies use: {{media:key}} becomes the returned URL, {{alt:key}}
 * the alternative text below.
 */
const SCREENSHOTS = [
    'matrice-droits' => [
        'file' => 'matrice-droits.webp',
        'alt' => 'Matrice des droits par utilisateur et par boîte mail',
    ],
    'administration' => [
        'file' => 'administration-capmail.webp',
        'alt' => 'Écran d\'administration de Capmail',
    ],
    'lead-mode' => [
        'file' => 'lead-mode-interface.webp',
        'alt' => 'Interface Lead Mode en trois colonnes',
    ],
    'lead-mode-tiers' => [
        'file' => 'lead-mode-creation-tiers.webp',
        'alt' => 'Création d\'un tiers depuis un courriel entrant',
    ],
    'templates' => [
        'file' => 'template-extraction.webp',
        'alt' => 'Modèle d\'extraction des données d\'un formulaire',
    ],
    'onglet-tiers' => [
        'file' => 'onglet-tiers-capmail.webp',
        'alt' => 'Onglet Capmail sur la fiche d\'un tiers',
    ],
];

// ---------------------------------------------------------------------
// Content
// ---------------------------------------------------------------------

/**
 * The project sheet. It carries NO Dolibarr compatibility on purpose
 * (SPEC 4.2): anything dated lives in the feed, on the article.
 */
const PROJECT = [
    'name' => 'Capmail',
    'summary' => 'Gestion des courriels entrants et sortants dans Dolibarr : boîtes IMAP multiples, traitement des demandes entrantes, rattachement aux tiers.',
    'license' => 'GPL-3.0-or-later',
    'description' => <<<'MD'
        Capmail centralise la gestion des courriels dans Dolibarr. Le module
        collecte les messages depuis une ou plusieurs boîtes IMAP, identifie
        les expéditeurs, les rattache aux tiers correspondants et conserve
        l'historique des échanges sur la fiche.

        L'interface principale est une vue en trois colonnes - liste,
        prévisualisation, actions - conçue pour traiter un courriel entrant
        comme une demande à qualifier : création rapide d'un tiers, d'un
        contact, d'un projet ou d'un ticket depuis le message. Une vue liste
        classique reste disponible pour les recherches avancées.

        Les dossiers IMAP sont présentés sous forme d'étiquettes, et les
        marqueurs restent synchronisés dans les deux sens avec le serveur :
        lu, non lu, étoile et quatre marqueurs utilisateur compatibles
        Thunderbird.
        MD,
];

/** The sheet translated for the English locale (SPEC D14). */
const PROJECT_TRANSLATION = [
    'locale' => 'en_US',
    'name' => 'Capmail',
    'summary' => 'Incoming and outgoing mail handling inside Dolibarr: multiple IMAP mailboxes, lead processing, thirdparty linking.',
    'description' => <<<'MD'
        Capmail centralises mail handling inside Dolibarr. The module
        collects messages from one or several IMAP mailboxes, identifies
        senders, links them to the matching thirdparties and keeps the
        conversation history on the record.

        Its main interface is a three-column view - list, preview, actions -
        designed to process an incoming message as a request to qualify:
        quick creation of a thirdparty, a contact, a project or a ticket
        straight from the mail. A classic list view remains available for
        advanced searches.

        IMAP folders are presented as tags, and flags stay synchronised both
        ways with the server: read, unread, star and four user flags
        compatible with Thunderbird.
        MD,
];

/**
 * The articles, in submission order. Each carries its French source and
 * its English translation; the translation reuses the media bound to the
 * source, which keeps serving them.
 */
const ARTICLES = [
    [
        'type' => 'release',
        'focus' => 'security',
        'version' => '2.0.4',
        'maturity' => 'stable',
        'compat_status' => 'declared',
        'dolibarr_min' => 18,
        'locale' => 'fr_FR',
        'screenshots' => ['matrice-droits', 'administration'],
        'title' => 'Capmail 2.0.4 : sécurité, droits et dossiers IMAP',
        'summary' => 'Version de sécurité et de fiabilité. Le cloisonnement multi-société est appliqué au niveau du module, la matrice des droits est vérifiée partout, les actions modifiantes exigent un jeton. Les sujets accentués ne sont plus amputés, les dossiers IMAP sont résolus chez les hébergeurs qui utilisent un point comme séparateur, STARTTLS est proposé pour le SMTP par boîte et le test de connexion authentifie réellement.',
        'body' => <<<'MD'
            Capmail 2.0.4 est une version de sécurité et de fiabilité. Elle
            n'ajoute pas d'interface : elle durcit l'ensemble des accès et corrige
            des défauts qui empêchaient le module de fonctionner chez certains
            hébergeurs. Elle reprend aussi les changements de la 2.0.3, qui
            n'avait pas été publiée.

            La mise à jour est recommandée pour toutes les installations, et
            particulièrement pour celles qui exploitent Capmail en multi-société
            ou qui accordent des droits différenciés sur plusieurs boîtes.

            ## Sécurité

            Une revue complète des accès a été menée.

            - **Cloisonnement multi-société** : messages, boîtes mail, profils
              d'expéditeur et étiquettes sont désormais filtrés par entité au
              niveau du module, au lieu de dépendre d'un filtrage indirect par
              la boîte mail.
            - **Matrice des droits appliquée partout** : les droits par
              utilisateur et par boîte sont vérifiés aussi sur les actions de
              masse, sur l'onglet Capmail de la fiche tiers, sur les
              identifiants transmis par les formulaires et sur le téléchargement
              des pièces jointes.
            - **Jeton anti-CSRF** exigé sur les actions qui modifient des
              données.
            - **Assainissement du HTML** des courriels avant affichage dans
              l'éditeur de réponse, à l'impression et dans les sélecteurs de
              modèles.
            - **Modèles et profils d'expéditeur privés** réellement limités à
              leur propriétaire.
            - Durcissement du traitement des pièces jointes, des entêtes des
              fichiers EML et des sujets.
            - Le contrôleur REST vérifie l'entité, les droits sur la boîte et la
              confidentialité ; les points d'entrée internes sont bloqués et les
              certificats TLS validés.

            ![{{alt:matrice-droits}}]({{media:matrice-droits}})

            ## Les accents ne sont plus perdus

            Un sujet contenant des accents était amputé de ses caractères
            accentués : *Demande de réservation à Nîmes* était enregistré sans
            ses accents ni les lettres correspondantes. Le sujet était décodé
            deux fois, et le second passage supprimait tout caractère non ASCII.

            C'est corrigé pour les nouveaux messages. Les messages déjà reçus
            gardent le sujet tronqué enregistré à l'époque.

            La détection de l'expéditeur a également été améliorée, notamment
            lorsque son adresse appartient au domaine de messagerie de la
            société.

            ## Dossiers IMAP : trois cas qui ne fonctionnaient pas

            Le symptôme était toujours le même : Capmail considérait un dossier
            comme inexistant, la copie dans le dossier des messages envoyés
            était abandonnée sans erreur visible, et l'archivage tentait de
            recréer des dossiers déjà présents.

            - **Serveurs utilisant un point comme séparateur** (OVH MX Plan et
              beaucoup de serveurs Dovecot) : un chemin comme `INBOX.Sent`
              n'était pas reconnu.
            - **Dossiers dont le nom contient un accent** : les noms de dossiers
              circulent dans un encodage particulier que le module ne décodait
              pas.
            - **Noms de dossiers ambigus**, deux dossiers portant le même nom
              sous des parents différents : le module en choisissait un au
              hasard. Il signale maintenant l'ambiguïté et invite à saisir le
              chemin complet.

            ## Deux nouveaux outils d'administration

            Le bouton **Détecter les dossiers**, sur la fiche d'une boîte,
            interroge le serveur, compare la configuration à la réalité et
            propose les bonnes valeurs à partir des attributs standard publiés
            par le serveur. **Appliquer les dossiers détectés** les enregistre.
            C'est désormais la méthode recommandée : les conventions varient
            beaucoup d'un hébergeur à l'autre.

            L'onglet Avancé ajoute la **réparation des dossiers au préfixe
            dupliqué**, du type `INBOX.INBOX.Sent`. Invisibles dans la plupart
            des webmails, ils accumulent du courrier que personne ne lit.
            L'outil déplace leurs messages vers le bon chemin, supprime le
            dossier vidé et corrige les références internes ; une simulation
            montre ce qui serait fait, et le dossier n'est supprimé qu'une fois
            ses messages effectivement déplacés.

            ![{{alt:administration}}]({{media:administration}})

            ## Envoi : STARTTLS et certificats

            - **STARTTLS est réellement proposé** pour le SMTP d'une boîte.
              L'option manquait dans le formulaire, ce qui rendait inutilisable
              tout serveur n'offrant que le port 587.
            - **Le test de connexion SMTP authentifie.** Il s'arrêtait avant
              l'authentification : un mot de passe erroné était annoncé comme un
              succès, et la panne n'apparaissait qu'au premier envoi.
            - **La validation du certificat TLS devient un réglage par boîte**,
              activée par défaut. La désactiver permet d'utiliser un serveur à
              certificat auto-signé, cas courant en auto-hébergement.

            ## Compatibilité par hébergeur

            Les limites réelles ont été vérifiées service par service :

            - OVH, Free et les serveurs Dovecot fonctionnent ;
            - Gmail fonctionne avec un mot de passe d'application obligatoire ;
            - Outlook.com et Microsoft 365 ne sont pas supportés : Microsoft a
              supprimé l'authentification par mot de passe et n'accepte plus
              qu'OAuth2, que le module ne gère pas ;
            - Proton Mail n'est pas supporté : aucun serveur IMAP public n'est
              exposé.

            ## Mise à jour

            Deux évolutions de la base sont appliquées à la réactivation du
            module : désactivez puis réactivez Capmail après avoir déposé les
            fichiers. Aucune action n'est nécessaire sur les boîtes existantes.
            Si des dossiers manquaient ou si la copie dans les messages envoyés
            ne se faisait pas, lancez **Détecter les dossiers** sur chaque boîte.

            ## Pour les développeurs

            Le module expose des hooks et des triggers pour les modules tiers,
            amorce une intégration avec dolipocket, et ajoute une campagne de
            tests automatisés contre de vrais serveurs IMAP et SMTP, en plus des
            tests d'isolation par entité et d'autorisation.
            MD,
        'translation' => [
            'locale' => 'en_US',
            'title' => 'Capmail 2.0.4: security, rights and IMAP folders',
            'summary' => 'A security and reliability release. Multi-entity isolation is now enforced by the module itself, the rights matrix is checked everywhere, and state-changing actions require a token. Accented subjects are no longer stripped, IMAP folders resolve on servers using a dot separator, STARTTLS is offered for per-mailbox SMTP and the connection test really authenticates.',
            'body' => <<<'MD'
                Capmail 2.0.4 is a security and reliability release. It adds no new
                interface: it hardens every access path and fixes defects that kept
                the module from working with some hosting providers. It also carries
                the changes of 2.0.3, which was never released.

                The update is recommended for every installation, and especially for
                those running Capmail across several entities or granting different
                rights on several mailboxes.

                ## Security

                A full review of access paths was carried out.

                - **Multi-entity isolation**: messages, mailboxes, sender profiles
                  and tags are now filtered by entity in the module itself, instead
                  of relying on indirect filtering through the mailbox.
                - **Rights matrix enforced everywhere**: per-user and per-mailbox
                  rights are now checked on mass actions, on the Capmail tab of the
                  thirdparty record, on identifiers posted by forms and on
                  attachment downloads.
                - **Anti-CSRF token** required on state-changing actions.
                - **Mail HTML is sanitised** before it reaches the reply editor, the
                  print view and the template pickers.
                - **Private templates and sender profiles** are genuinely limited to
                  their owner.
                - Hardened handling of attachments, EML headers and subjects.
                - The REST controller checks the entity, mailbox rights and privacy;
                  internal endpoints are blocked and TLS certificates verified.

                ![{{alt:matrice-droits}}]({{media:matrice-droits}})

                ## Accents are no longer lost

                A subject containing accents lost its accented characters
                altogether. The subject was decoded twice, and the second pass
                dropped every non-ASCII character.

                New messages are fixed. Messages already received keep the truncated
                subject stored at the time.

                Sender detection was improved as well, in particular when the sender
                address belongs to the company's own mail domain.

                ## IMAP folders: three cases that did not work

                The symptom was always the same: Capmail considered a folder
                missing, the copy to the sent folder was dropped without a visible
                error, and archiving tried to recreate folders that already existed.

                - **Servers using a dot as separator** (OVH MX Plan and many Dovecot
                  servers): a path such as `INBOX.Sent` was not recognised.
                - **Folders whose name contains an accent**: folder names travel in
                  a specific encoding the module did not decode.
                - **Ambiguous folder names**, two folders sharing a name under
                  different parents: the module picked one at random. It now reports
                  the ambiguity and asks for the full path.

                ## Two new administration tools

                The **Detect folders** button, on a mailbox record, queries the
                server, compares the configuration against reality and suggests the
                right values from the standard attributes the server publishes.
                **Apply detected folders** stores them. This is now the recommended
                method: conventions differ widely between providers.

                The Advanced tab adds the **repair of folders with a duplicated
                prefix**, such as `INBOX.INBOX.Sent`. Invisible in most webmails,
                they pile up mail nobody reads. The tool moves their messages to the
                right path, removes the emptied folder and fixes internal
                references; a dry run shows what would happen, and the folder is
                only removed once its messages have actually moved.

                ![{{alt:administration}}]({{media:administration}})

                ## Sending: STARTTLS and certificates

                - **STARTTLS is really offered** for per-mailbox SMTP. The option
                  was missing from the form, which made any server offering port 587
                  alone unusable.
                - **The SMTP connection test authenticates.** It stopped before
                  authentication: a wrong password was reported as a success, and
                  the failure only surfaced on the first send.
                - **TLS certificate validation becomes a per-mailbox setting**,
                  enabled by default. Turning it off allows a self-signed
                  certificate, a common case in self-hosting.

                ## Compatibility by provider

                Actual limits were verified service by service:

                - OVH, Free and Dovecot servers work;
                - Gmail works with a mandatory application password;
                - Outlook.com and Microsoft 365 are not supported: Microsoft removed
                  password authentication and only accepts OAuth2, which the module
                  does not handle;
                - Proton Mail is not supported: no public IMAP server is exposed.

                ## Updating

                Two database changes are applied when the module is re-enabled:
                disable then enable Capmail after uploading the files. Nothing is
                required on existing mailboxes. If folders were missing or the copy
                to the sent folder did not happen, run **Detect folders** on each
                mailbox.

                ## For developers

                The module exposes hooks and triggers for third-party modules, starts
                an integration with dolipocket, and adds an automated campaign against
                real IMAP and SMTP servers, on top of new entity isolation and
                authorisation tests.
                MD,
        ],
    ],
    [
        'type' => 'release',
        'focus' => 'feature_major',
        'version' => '2.0.1',
        'maturity' => 'stable',
        'compat_status' => 'declared',
        'dolibarr_min' => 18,
        'locale' => 'fr_FR',
        'screenshots' => ['lead-mode', 'templates', 'lead-mode-tiers', 'onglet-tiers'],
        'title' => 'Capmail 2.0.1 : Lead Mode, multi-boîtes et étiquettes',
        'summary' => 'Refonte majeure. Une interface en trois colonnes devient la vue par défaut, le module gère plusieurs boîtes IMAP avec une matrice de droits par utilisateur, les dossiers IMAP laissent place à des étiquettes, et les marqueurs sont synchronisés dans les deux sens avec le serveur. Les modèles de détection et d\'extraction transforment un formulaire de site en tiers, contact ou projet.',
        'body' => <<<'MD'
            La 2.0 est une refonte du module. Elle introduit une nouvelle
            interface de traitement, le support de plusieurs boîtes mail, un
            système d'étiquettes en remplacement de la navigation par dossiers
            IMAP, et le traitement des demandes entrantes.

            ## Le Lead Mode devient la vue par défaut

            Le Lead Mode remplace la vue liste classique comme interface
            principale. C'est une vue en trois colonnes redimensionnables.

            - **Colonne gauche** : la liste des courriels, avec défilement
              infini, badges et indicateurs - non lu, étoile, étiquettes, fil de
              conversation.
            - **Colonne centrale** : la prévisualisation du message sélectionné,
              les actions rapides et les données extraites automatiquement.
            - **Colonne droite** : les actions de traitement - créer ou lier un
              tiers, répondre, consulter l'agenda.

            La vue liste classique reste disponible pour les recherches
            avancées. S'y ajoutent des raccourcis clavier et des notifications
            du navigateur à l'arrivée d'un message.

            ![{{alt:lead-mode}}]({{media:lead-mode}})

            ## Plusieurs boîtes mail

            Le module gère désormais plusieurs boîtes IMAP, chacune avec ses
            identifiants et sa couleur d'identification, et une matrice de
            droits par utilisateur et par boîte. Chaque utilisateur peut en
            outre connecter une boîte personnelle.

            La configuration SMTP devient propre à chaque boîte, avec une
            adresse d'expédition dédiée et un repli sur la configuration
            globale de Dolibarr. Un sélecteur de boîte apparaît dans les
            formulaires d'envoi standard - propositions, commandes, factures -
            restreint par les droits d'envoi de chaque utilisateur.

            ## Les étiquettes remplacent les dossiers

            La navigation par dossiers IMAP laisse place à un système
            d'étiquettes. Les dossiers du serveur sont automatiquement
            transposés en étiquettes, et un glisser-déposer suffit à classer un
            message.

            La synchronisation des marqueurs est bidirectionnelle : lu et non
            lu suivent `\Seen`, l'étoile suit `\Flagged`, et les quatre premiers
            marqueurs utilisateur suivent les mots-clés `$Label1` à `$Label4`,
            compatibles Thunderbird. Les marqueurs 5 à 8 restent locaux.

            ## Traitement des demandes entrantes

            Les modèles de détection et d'extraction reconnaissent les messages
            produits par un formulaire de site - Contact Form 7, Wix, ou un
            formulaire maison - et en extraient les champs utiles. Un détecteur
            de doublons signale les demandes déjà traitées.

            ![{{alt:templates}}]({{media:templates}})

            Depuis un message, la création d'un tiers, d'un contact ou d'un
            projet se fait en une étape, avec les données déjà extraites.

            ![{{alt:lead-mode-tiers}}]({{media:lead-mode-tiers}})

            Un onglet Capmail sur la fiche tiers présente l'historique complet
            des échanges, et un onglet Actions montre les événements d'agenda à
            venir du tiers rattaché.

            ![{{alt:onglet-tiers}}]({{media:onglet-tiers}})

            ## Autres changements

            - Reconstruction du fil de conversation à partir des entêtes
              `In-Reply-To` et `References`.
            - Mise en attente d'un message jusqu'à une date choisie, avec
              réapparition automatique.
            - Rendu HTML sécurisé : iframe isolée, politique de sécurité du
              contenu stricte, aucun référent transmis.
            - Propagation du rattachement au tiers sur les messages liés.
            - Administration réorganisée en quatre onglets thématiques, avec un
              parcours de première boîte lorsqu'aucune n'est configurée, et des
              outils de maintenance ponctuels.
            - TinyMCE est supporté en plus de CKEditor, et un éditeur dédié est
              disponible pour les envois en nombre.
            MD,
        'translation' => [
            'locale' => 'en_US',
            'title' => 'Capmail 2.0.1: Lead Mode, multi-mailbox and tags',
            'summary' => 'A major rework. A three-column interface becomes the default view, the module handles several IMAP mailboxes with a per-user rights matrix, IMAP folders give way to tags, and flags are synchronised both ways with the server. Detection and extraction templates turn a website form into a thirdparty, a contact or a project.',
            'body' => <<<'MD'
                Version 2.0 is a rework of the module. It introduces a new processing
                interface, support for several mailboxes, a tag system replacing
                IMAP folder navigation, and the handling of incoming requests.

                ## Lead Mode becomes the default view

                Lead Mode replaces the classic list as the main interface. It is a
                three-column, resizable view.

                - **Left column**: the mail list, with infinite scrolling, badges and
                  indicators - unread, star, tags, conversation thread.
                - **Centre column**: the preview of the selected message, quick
                  actions and automatically extracted data.
                - **Right column**: processing actions - create or link a
                  thirdparty, reply, check the agenda.

                The classic list view remains available for advanced searches.
                Keyboard shortcuts and browser notifications on new mail come along
                with it.

                ![{{alt:lead-mode}}]({{media:lead-mode}})

                ## Several mailboxes

                The module now handles several IMAP mailboxes, each with its own
                credentials and identifying colour, and a per-user, per-mailbox
                rights matrix. Each user may also connect a personal mailbox.

                SMTP configuration becomes specific to each mailbox, with its own
                sending address and a fallback on the global Dolibarr configuration.
                A mailbox picker appears in the standard sending forms - proposals,
                orders, invoices - restricted by each user's sending rights.

                ## Tags replace folders

                IMAP folder navigation gives way to a tag system. Server folders are
                automatically mapped to tags, and a drag and drop is enough to file
                a message.

                Flag synchronisation works both ways: read and unread follow
                `\Seen`, the star follows `\Flagged`, and the first four user flags
                follow the `$Label1` to `$Label4` keywords, compatible with
                Thunderbird. Flags 5 to 8 stay local.

                ## Handling incoming requests

                Detection and extraction templates recognise messages produced by a
                website form - Contact Form 7, Wix, or a custom form - and pull out
                the useful fields. A duplicate detector flags requests already
                handled.

                ![{{alt:templates}}]({{media:templates}})

                From a message, creating a thirdparty, a contact or a project takes
                one step, with the extracted data already filled in.

                ![{{alt:lead-mode-tiers}}]({{media:lead-mode-tiers}})

                A Capmail tab on the thirdparty record shows the full history of
                exchanges, and an Actions tab shows the upcoming agenda events of
                the linked thirdparty.

                ![{{alt:onglet-tiers}}]({{media:onglet-tiers}})

                ## Other changes

                - Conversation threads rebuilt from the `In-Reply-To` and
                  `References` headers.
                - Snoozing a message until a chosen date, with automatic
                  reappearance.
                - Secure HTML rendering: sandboxed iframe, strict content security
                  policy, no referrer sent.
                - Thirdparty link propagation across related messages.
                - Administration reorganised into four thematic tabs, with a
                  first-mailbox walkthrough when none is configured, and one-shot
                  maintenance tools.
                - TinyMCE is supported alongside CKEditor, and a dedicated editor is
                  available for bulk sending.
                MD,
        ],
    ],
];

// ---------------------------------------------------------------------
// Runtime
// ---------------------------------------------------------------------

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

dolinews_configure(API_BASE, API_TOKEN);

say('Cible : '.API_BASE.($dryRun ? ' (simulation)' : ''));

$profile = requireContributorProfile();

$editor = resolveEditor($profile['editors'] ?? [], $dryRun, [
    'slug' => EDITOR_SLUG,
    'name' => EDITOR_NAME,
    'contact_email' => EDITOR_CONTACT_EMAIL,
    'website' => EDITOR_WEBSITE,
    'description' => EDITOR_DESCRIPTION,
]);
say('Éditeur : '.$editor['name'].' (#'.$editor['id'].', rôle '.$editor['role'].')');

// --- Project sheet ---------------------------------------------------

$projectSlug = slugify(PROJECT['name']);
$project = apiGetOrNull('/projects/'.$projectSlug);

if ($project !== null) {
    say('Fiche projet déjà présente : '.$project['slug'].' (#'.$project['id'].'), réutilisée.');
} elseif ($dryRun) {
    say('Fiche projet à créer : '.PROJECT['name']);
    $project = ['id' => 0, 'slug' => $projectSlug];
} else {
    $project = apiPost('/projects', PROJECT + ['editor_id' => $editor['id']]);
    say('Fiche projet créée : '.$project['slug'].' (#'.$project['id'].')');
}

$existingLinks = array_column($project['links'] ?? [], 'url');

foreach (PROJECT_LINKS as $link) {
    if (in_array($link['url'], $existingLinks, true)) {
        say('Lien déjà présent : '.$link['url']);

        continue;
    }

    if ($dryRun) {
        say('Lien à ajouter : '.$link['type'].' '.$link['url']);

        continue;
    }

    apiPost('/projects/'.$project['slug'].'/links', $link);
    say('Lien ajouté : '.$link['type'].' '.$link['url']);
}

$existingLocales = array_column($project['translations'] ?? [], 'locale');

if (in_array(PROJECT_TRANSLATION['locale'], $existingLocales, true)) {
    say('Traduction de fiche déjà présente : '.PROJECT_TRANSLATION['locale']);
} elseif ($dryRun) {
    say('Traduction de fiche à créer : '.PROJECT_TRANSLATION['locale']);
} else {
    apiPost('/projects/'.$project['slug'].'/translations', PROJECT_TRANSLATION);
    say('Traduction de fiche créée : '.PROJECT_TRANSLATION['locale']);
}

// --- Step one: the media ---------------------------------------------

// Alternative texts, keyed like the placeholders: the body carries
// {{alt:key}}, the deposited medium only knows its URL.
$alts = array_map(static fn (array $shot): string => $shot['alt'], SCREENSHOTS);

$media = [];

foreach (SCREENSHOTS as $key => $shot) {
    $path = SCREENSHOT_DIR.'/'.$shot['file'];

    if (! is_readable($path)) {
        fail('Capture introuvable ou illisible : '.$path);
    }

    if ($dryRun) {
        say('Capture à déposer : '.$shot['file']);
        $media[$key] = ['id' => 0, 'url' => 'about:blank'];

        continue;
    }

    $deposited = apiUpload('/media', $path, [
        'editor_id' => (string) $editor['id'],
        'alt' => $shot['alt'],
    ]);

    $media[$key] = $deposited;
    say('Capture déposée : '.$shot['file'].' -> #'.$deposited['id'].' ('.$deposited['mime'].', '
        .$deposited['width'].'x'.$deposited['height'].')');
}

// --- Step two: the articles ------------------------------------------

foreach (ARTICLES as $definition) {
    $body = expand($definition['body'], $media, $alts);

    // Only the screenshots this article shows: a medium belongs to one
    // article, and binding them all to the first would make the second
    // depend on the survival of the first.
    $mediaIds = array_map(
        static fn (string $key): int => (int) $media[$key]['id'],
        $definition['screenshots'],
    );

    $payload = [
        'editor_id' => $editor['id'],
        'project_id' => $project['id'],
        'type' => $definition['type'],
        'focus' => $definition['focus'],
        'version' => $definition['version'],
        'maturity' => $definition['maturity'],
        'compat_status' => $definition['compat_status'],
        'dolibarr_min' => $definition['dolibarr_min'],
        'locale' => $definition['locale'],
        'title' => $definition['title'],
        'summary' => $definition['summary'],
        'body' => $body,
        'media_ids' => $mediaIds,
        'submit' => true,
    ];

    if ($dryRun) {
        say('Article à soumettre : '.$definition['title'].' ('.strlen($body).' octets)');
        say('  Traduction à soumettre : '.$definition['translation']['title']);

        continue;
    }

    $article = apiPost('/articles', $payload);
    say('Article soumis : #'.$article['id'].' '.$article['title'].' ['.$article['status'].']');

    $translation = apiPost('/articles/'.$article['id'].'/translations', [
        'locale' => $definition['translation']['locale'],
        'title' => $definition['translation']['title'],
        'summary' => $definition['translation']['summary'],
        'body' => expand($definition['translation']['body'], $media, $alts),
        'submit' => true,
    ]);

    say('Traduction soumise : #'.$translation['id'].' '.$translation['locale']
        .' ['.$translation['status'].']');
}

say('Terminé. Un jeton donne le droit de soumettre, jamais celui de publier :');
say('les articles attendent la revue dans le back-office.');

exit(0);
