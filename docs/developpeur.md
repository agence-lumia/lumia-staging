# Lümia Staging — documentation développeur

## Fonctions publiques

| Fonction | Rôle |
|---|---|
| `lmv_get_source_id( int $post_id = 0 ): int` | ID de l'original si le contenu est une version, sinon l'ID lui-même. À utiliser dans les snippets conditionnés à un ID (FluentSnippets…). |
| `lmv_is_version( int $post_id ): bool` | Le contenu est-il une version de travail ? |

## Actions

| Hook | Arguments | Quand |
|---|---|---|
| `lmv_version_created` | `$version_id, $source_id` | Version créée |
| `lmv_version_state_changed` | `$version_id, $to, $from` | Changement d'état |
| `lmv_version_abandoned` | `$version_id, $source_id` | Avant suppression d'une version abandonnée |
| `lmv_before_step` / `lmv_after_step` | `$step, $item` | Autour de chaque étape de publication (`snapshot`, `copy`, `css`, `cache`). Une exception annule toute la publication. |
| `lmv_after_publish` | `$post_id, $version_id, $snapshot_id` | Publication terminée (aussi après une restauration, `$version_id = 0`). Brancher ici d'autres caches. |
| `lmv_after_restore` | `$post_id, $snapshot_id, $backup_id` | Restauration terminée |
| `lmv_purge_cache` | `$post_id, $global` | Purge de cache (après Cache Enabler) |
| `lmv_client_feedback` | `$version_id, $decision, $name, $comment` | Retour client reçu |

## Filtres

| Filtre | Défaut | Rôle |
|---|---|---|
| `lmv_default_caps` | `['administrator' => [...5 capacités]]` | Rôles recevant les capacités à l'activation |
| `lmv_post_types` | réglage (`page`, `bricks_template`) | Types versionnables |
| `lmv_meta_whitelist` | `['_bricks_*']` | Clés copiées (motifs, `*` final) |
| `lmv_meta_blacklist` | `['_bricks_lock*']` | Clés jamais copiées |
| `lmv_reference_keys` | `postId, post_id, pageId, page_id, objectId, templateId` | Clés d'ID remplacées (R2) |
| `lmv_hash_ignored_keys` | `['_bricks_lock']` | Clés ignorées par la détection de conflit |
| `lmv_bricks_global_options` | classes, variables, palette, styles, composants | Options surveillées (R8) |
| `lmv_bricks_available` | thème `bricks` ou `BRICKS_VERSION` | Forcer la détection de Bricks |
| `lmv_client_ip` | `REMOTE_ADDR` | IP réelle derrière un proxy (limitation des essais) |
| `lmv_github_repo` | `agence-lumia/lumia-staging` | Dépôt des mises à jour |

## WP-CLI

```
wp lmv list [--state=<state>] [--format=table|json|csv|ids]
wp lmv create <post_id> [--note=<note>] --user=<id>
wp lmv publish <version_id>... [--note=<note>] [--force]
wp lmv restore <snapshot_id> [--yes]
wp lmv history <post_id> [--format=...]
wp lmv cleanup
wp lmv schema-rollback <version>
```

## API REST interne (`lumia-staging/v1`)

Authentification par cookie + nonce `X-WP-Nonce`. Chaque route vérifie une capacité `lmv_*` et `edit_post` sur l'original.

`GET/POST /versions`, `GET /versions/{id}`, `GET /versions/{id}/state`, `POST /versions/{id}/note`, `POST /versions/{id}/abandon`, `GET /versions/{id}/export`, `GET /versions/{id}/summary`, `POST /versions/{id}/publish`, `POST|DELETE /versions/{id}/schedule`, `POST /versions/{id}/share`, `DELETE /versions/{id}/share/{token}`, `POST /batches`, `DELETE /batches/{id}`, `GET /posts/{id}/status`, `GET /posts/{id}/history`, `POST /snapshots/{id}/restore`, `GET /compare`, `GET /pages`, `GET /log`, `GET|POST /settings`.

## Données

| Stockage | Contenu |
|---|---|
| Posts au statut `lmv-version` | Versions ; métas `_lmv_source_id`, `_lmv_source_hash`, `_lmv_globals_hash`, `_lmv_state`, `_lmv_note`, `_lmv_scheduled_at`, `_lmv_batch_id`, `_lmv_bricks_version`, `_lmv_orphan` |
| `{prefix}lmv_snapshots` | Sauvegardes (métas brutes gz+base64, champs, termes) ; statut `pending/committed/rolled_back/recovered` |
| `{prefix}lmv_tokens` | Empreintes HMAC des jetons d'aperçu |
| `{prefix}lmv_feedback` | Retours client |
| `{prefix}lmv_batches` | Lots |
| `{prefix}lmv_log` | Journal |

## BricksAdapter

Toute dépendance à Bricks passe par `Lumia\Staging\Adapter\BricksAdapter`. Voir la section « Lot 0 » de `CLAUDE.md` pour les hypothèses à vérifier à chaque mise à jour majeure de Bricks.
