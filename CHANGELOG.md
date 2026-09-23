# Changelog

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versionnage sémantique.
Les entrées s'ajoutent sous « Non publié » ; la release stable renomme la section avec la version et la date (`bin/changelog.sh promote`).

## [Non publié]

## [0.1.0] — 2026-09-23

### Ajouté
- Versions de travail des pages et templates Bricks (statut `lmv-version`, jamais public).
- Publication sur le même ID (métas `_bricks_*` copiées dans l'original), verrou SQL, transaction InnoDB, retour automatique en cas d'échec.
- Historique et restauration en un clic, annulable ; rétention paramétrable.
- Détection de conflit (empreinte de l'original) et des éléments globaux Bricks modifiés.
- Templates : conditions neutralisées dans la version, conservées à la publication ; changement de type bloqué ; aperçu sur une page choisie.
- Aperçu client par lien à jeton (256 bits, empreinte HMAC), validation / demande de modifications, e-mails, limitation des essais, blocage des soumissions et des paiements.
- Comparatif côte à côte ou curseur, formats alignés sur les points de rupture Bricks, défilement synchronisé, résumé structurel.
- Publication programmée (Action Scheduler ou WP-Cron) et lots tout ou rien.
- Reprise après incident (publication interrompue restaurée à la requête admin suivante).
- Tableau de bord, widget WordPress, journal exportable en CSV, réglages.
- Interface d'administration au langage Studio Kyne : barre latérale, pages en carte, réglages en lignes d'option.
- Bandeau compact et raccourcis dans l'éditeur Bricks ; bouton « Créer une version » réductible (et masquable dans les réglages) ; barre d'admin, actions de ligne.
- WP-CLI : `wp lmv list|create|publish|restore|history|cleanup|schema-rollback`.
- Mises à jour depuis les releases GitHub : canaux stable et dev, archive vérifiée par SHA256, notes de version dans la fiche, bascule « mises à jour automatiques » et vérification manuelle dans les réglages.

### Corrigé (avant publication, constaté sur Bricks 2.4)
- Le fichier CSS Bricks de la page publiée était réécrit sans les styles des éléments (dédoublonnage CSS de Bricks dans la même requête).
- Le CSS d'une version est généré dès sa création (aperçu et comparatif stylés) ; le CSS en ligne de l'aperçu est aussi forcé dans la copie en mémoire des réglages Bricks.
