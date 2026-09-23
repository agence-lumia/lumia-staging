# Lümia Staging

Versions de travail pour les pages et templates **Bricks** : modifier une page déjà en ligne sans que les visiteurs voient le travail en cours, la faire valider par le client via un lien, puis la publier en un clic **sur le même ID** (même URL, même SEO, même place dans les menus). Retour arrière en un clic.

- WordPress 6.8+, PHP 8.1+, thème Bricks 2.x. WooCommerce optionnel.
- Guide utilisateur : [docs/guide-utilisateur.md](docs/guide-utilisateur.md)
- Documentation développeur (hooks, filtres, WP-CLI, API) : [docs/developpeur.md](docs/developpeur.md)
- Notes techniques, arbitrages et points du lot 0 : [CLAUDE.md](CLAUDE.md)

## Développement

```bash
npm ci && npm run build                     # interface d'administration (build/)
docker run --rm -v "$PWD:/app" -w /app composer:2 composer install
docker run --rm -v "$PWD:/app" -w /app composer:2 composer ci   # PHPCS, PHPStan 8, PHPUnit
docker compose -f tests/integration/docker-compose.yml up --abort-on-container-exit --exit-code-from wp
```

## Release

Tag `vX.Y.Z` → la CI publie `lumia-staging-X.Y.Z.zip` + `SHA256SUMS` ; les sites se mettent à jour depuis l'admin WordPress.
