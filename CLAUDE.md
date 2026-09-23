# CLAUDE.md — lumia-staging

Plugin WordPress « Lümia Staging » (ex-« Lümia Versions » dans le cahier des charges) : versions de travail des pages et templates Bricks, publiées sur le même ID.

## Carte du code

- `lumia-staging.php` : prérequis PHP/WP puis `Plugin::boot` (plugins_loaded, priorité 5). Aucune logique.
- `src/Plugin.php` : conteneur de services et branchement des hooks. `boot()` = toujours actif (statut, aperçu, mises à jour) ; `boot_features()` (after_setup_theme) = seulement si Bricks est le thème.
- `src/Adapter/BricksAdapter.php` : **seul fichier qui touche Bricks**. Une mise à jour de Bricks ne doit casser que lui.
- `src/Publish/PublishPipeline.php` : publication / lot / restauration / rollback. Une publication simple est un lot d'un élément.
- `src/Preview/PreviewController.php` : aperçu client (jeton) et URL signées (comparatif, historique).
- `src/Post/PostStatus.php` : statut `lmv-version` et toutes les protections de visibilité.
- `src-js/admin` : écrans React (`@wordpress/scripts`) ; `assets/builder/ui.js` : bandeau builder + front (vanilla) ; `assets/preview/toolbar.js` : barre client (Shadow DOM, < 15 Ko).

## Arbitrages verrouillés (ne pas rediscuter sans raison)

- **Copier dans l'original, jamais le remplacer.** ID, slug, menus, SEO, réglages WooCommerce restent intacts.
- **Nom :** slug/text domain `lumia-staging`, namespace `Lumia\Staging`, mais préfixe code **`lmv_`** conservé (tables, métas, capacités, hooks, constantes `LMV_`).
- **Aucune dépendance d'exécution** : autoload maison (`src/autoload.php`). Composer = outils de dev uniquement ; `vendor/` n'est pas livré.
- **Sauvegardes brutes** : `SnapshotRepository` stocke les `meta_value` telles qu'en base (gz + base64) et les réécrit par SQL → restauration à l'octet près, sans désérialiser. La copie version → original passe par l'API (`update_post_meta` + `wp_slash`) pour appliquer R2.
- **Verrou = `GET_LOCK()`** (nom préfixé par empreinte DB+préfixe, les noms sont globaux au serveur SQL). La reprise après incident s'appuie dessus : verrou libre + sauvegarde `pending` ⇒ PHP est mort ⇒ on restaure.
- **Ordre du commit** : sauvegardes marquées `committed` en une seule requête *avant* la suppression des versions. Arrêt après le commit = versions `published` résiduelles supprimées par `RecoveryService`.
- **Aperçu = jeton dans l'URL, jamais de cookie** (nginx sert Cache Enabler avant PHP sauf si query string). Les liens internes sont réécrits par un buffer de sortie, admin-ajax/wc-ajax/REST portent le jeton pour être bloqués en POST.
- **Aperçu rendu anonyme** : `determine_current_user` → 0 dès plugins_loaded.
- **CSS en aperçu : forcé « en ligne »** (`option_bricks_global_settings` sans `cssLoading`) : sinon Bricks lirait le fichier CSS de l'original.
- **Aperçu de sauvegarde** : rendu de l'original avec `get_post_metadata` substitué (pas de version temporaire).
- **Rétention** : suppression si rang > N **ou** âge > D jours, mais la sauvegarde la plus récente de chaque contenu est toujours gardée.
- **Publication programmée en conflit** : échoue (e-mail), ne force jamais.
- **Retour client** : « Valider » → Validée ; « Demander des modifications » → En cours (diagramme §4). Publier depuis « En attente du client » est permis avec alerte.

## Lot 0 — points à confirmer sur une vraie installation Bricks

| Point | Hypothèse codée | Où |
|---|---|---|
| Noms des métas | constantes `BRICKS_DB_*` sinon `_bricks_page_content_2`, `_bricks_page_header_2`, `_bricks_page_footer_2`, `_bricks_page_settings`, `_bricks_template_type`, `_bricks_template_settings` (conditions dans `templateConditions`) | `BricksAdapter` |
| Régénération CSS | **Confirmé sur Bricks 2.4** : `Assets::reset_duplication_tracking()` puis `Assets_Files::regenerate_css_file( $id, 1, true )` (index ≠ 0 : l'index 0 efface tous les CSS du site) ; repli `generate_post_css_file( $id, $zone, $elements )` ; sinon suppression du fichier + alerte | `regenerate_css()` |
| Templates actifs | filtre `bricks/active_templates` | `swap_active_template()` |
| Édition d'un statut custom | Bricks ouvre et enregistre un contenu `lmv-version` (statut `protected` → visible pour qui a `edit_post`) ; le bouton « Publier » de Bricks est neutralisé par `PostStatus::guard_status` | à tester |
| Rendu d'un statut non publié | Bricks rend le contenu d'un post non `publish` en front | à tester |
| Sélecteurs Bricks | versions absentes (statut `exclude_from_search` ⇒ exclu de `post_status=any`) | à tester |
| Raccourcis Ctrl/Cmd+Maj+P / D | pas de collision avec Bricks | `assets/builder/ui.js` |
| Chargement du script builder | `wp_enqueue_scripts` + `bricks_is_builder_main()` | `BuilderIntegration` |
| IP réelle derrière Traefik | `REMOTE_ADDR` ; sinon filtre `lmv_client_ip` | `RateLimiter` |

## Commandes

```bash
# Tests d'intégration (WordPress + MariaDB + faux Bricks), ~2 min
docker compose -f tests/integration/docker-compose.yml up --abort-on-container-exit --exit-code-from wp
PHP_VERSION=8.1 WP_VERSION=6.8 docker compose -f tests/integration/docker-compose.yml up --abort-on-container-exit --exit-code-from wp

# Qualité PHP (pas de PHP local sur le poste : via Docker)
docker run --rm -v "$PWD:/app" -w /app composer:2 composer ci

# JS
npm ci && npm run build        # build/admin.js + admin.css
npm run lint:js
```

Diagnostic sur un site :

```bash
wp lmv list
wp lmv history <post_id>
wp lmv restore <snapshot_id>          # l'inverse est affiché après coup
wp lmv cleanup                        # reprise après incident + rétention
wp db query "SELECT id,post_id,status,created_at FROM wp_lmv_snapshots WHERE status='pending'"
wp db query "SELECT created_at,action,level,message FROM wp_lmv_log ORDER BY id DESC LIMIT 20"
```

## Pièges

- **CSS Bricks dédoublonné par requête** (`Assets::$unique_inline_css`) : toute génération après un `save_post` Bricks dans la même requête doit d'abord appeler `reset_duplication_tracking()`, sinon le fichier est réécrit sans les styles des éléments (bug constaté sur Néo-Nat, couvert par `scenario.php`).
- `Database::$global_settings` de Bricks est chargé avant nos filtres d'option : pour forcer le CSS en ligne, modifier aussi la copie en mémoire (`force_inline_css()`).
- Les sites Bricks peuvent imposer `scroll-behavior: smooth` : tout `scrollTo` programmatique (comparatif) doit passer `behavior: 'instant'`.
- `update_post_meta` **retire les antislashs** : toujours `wp_slash()` la valeur (le CSS et le JSON Bricks en contiennent).
- WP 5.6+ remet un contenu restauré de la corbeille en `draft` : filtré par `wp_untrash_post_status`.
- `get_post_meta( $id )` sans clé renvoie des valeurs **brutes sérialisées** (c'est voulu dans `BricksAdapter::raw_meta`).
- Les sous-pages admin cachées (Historique, Comparer) sont masquées par CSS, pas retirées du menu (sinon avertissements PHP 8 sur le titre).
- Le faux Bricks des tests (`tests/fixtures/bricks-stub`) ne remplace pas le lot 0.
- Hooks `lmv_before_step` : une exception levée fait échouer la publication — c'est ainsi que les tests simulent un échec.

## Tests

- Vérification visuelle : `.mcp.json` déclare le MCP Chrome DevTools (actif après rechargement de Claude Code). Stack locale de test : `../docker-compose.dev.yml` (localhost:8080, lancée par `start.bat`).
- `tests/unit` : classes pures (ReferenceReplacer, StructureDiff, State, format des jetons, sélection des releases et SHA256SUMS de l'updater).
- `tests/integration` : `scenario.php` (73 vérifications WP-CLI : publication, restauration, conflit, templates, lot avec échec, reprise, visibilité, programmation, jetons) et `http.php` (66 vérifications HTTP : aperçu, en-têtes, blocage, retours, signatures, limitation, écrans admin, REST).
- Bout en bout Playwright avec le vrai Bricks : à écrire (`tests/e2e`), job CI prêt, activé par `vars.E2E_ENABLED` + secret `BRICKS_ZIP_URL`.

## Release

Dépôt public `agence-lumia/lumia-staging`, modèle de studio-kyne-mini-tools. **Jamais de bump de version à la main** : la CI écrit `Version:`, `LMV_VERSION` et `package.json` (`bin/set-version.sh`).

- **Branches :** travail sur `dev` (branches `feat/…`, `fix/…` + PR vers `dev`, Conventional Commits), `main` = stable.
- **Push sur `dev`** → `release-dev.yml` : CI complète puis pré-release `vX.Y.Z-dev.N` (X.Y.Z = patch suivant la dernière stable), canal « dev » de l'updater. La version n'est écrite que dans l'archive (aucun commit sur `dev`). Les anciennes pré-releases dev sont supprimées.
- **Stable** → `gh workflow run release.yml -f bump=patch|minor|major` (sur `main`, après fusion de `dev`) : CI, version, section « Non publié » du `CHANGELOG.md` datée (`bin/changelog.sh promote`) et utilisée comme notes, commit `chore(release)` + tag sur `main`, ZIP + `SHA256SUMS`, puis `dev` ramenée sur `main` en avance rapide.
- **CHANGELOG :** ajouter chaque changement sous `## [Non publié]`.
- Archive : `bin/build-zip.sh` (exclusions dans `.distignore`), testable dans `node:22` avec `rsync zip`.
- Updater (`src/Update/GitHubUpdater.php`) : cache 12 h, échec en cache 15 min, notes `body_html`, SHA256 obligatoire, bascule « mises à jour auto » = option native `auto_update_plugins`. Dépôt privé un jour : `define( 'LMV_GITHUB_TOKEN', '…' );` (lecture seule).
