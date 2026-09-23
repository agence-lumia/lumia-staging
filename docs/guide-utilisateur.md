# Lümia Staging — guide utilisateur

Modifier une page ou un template Bricks déjà en ligne sans que les visiteurs voient le travail en cours, puis publier en un clic. L'adresse, le SEO et la place dans les menus ne changent pas.

## 1. Modifier une page en ligne

1. Dans **Pages** (ou **Bricks > Templates**), survolez la page et cliquez sur **Créer une version**. On peut aussi passer par la barre d'admin du site ou par le bouton **Créer une version** dans Bricks.
2. Bricks s'ouvre sur la *version de travail*. Un bandeau rappelle que les visiteurs voient toujours la version en ligne.
3. Travaillez et enregistrez autant que vous voulez. Pour reprendre plus tard : **Versions** dans le menu admin, ou **Reprendre la version** sur la page.
4. Cliquez sur **Publier** dans le bandeau (ou Ctrl/Cmd + Maj + P). La fenêtre résume les changements et affiche les éventuelles alertes. Après publication, **Annuler** reste disponible pendant 30 secondes.

> Enregistrez dans Bricks avant de publier : seul le contenu enregistré est publié.

## 2. Faire valider par le client

1. Dans le bandeau, cliquez sur **Aperçu client**, choisissez la durée du lien (7 jours par défaut), puis **Créer le lien**. Il est copié dans le presse-papiers.
2. Le client voit la page comme en production, avec une petite barre : **Avant / Après**, **Valider**, **Demander des modifications**. Il n'a pas besoin de compte.
3. Sa réponse arrive par e-mail et dans le tableau de bord **Versions**.

Un lien peut être révoqué à tout moment. Pendant l'aperçu, les formulaires et les commandes sont désactivés.

## 3. Programmer une mise en ligne

Dans la fenêtre de publication, cliquez sur **Programmer** et choisissez une date et une heure (fuseau du site). La mise en ligne se fait à 5 minutes près, puis un e-mail confirme le succès ou l'échec. Une version programmée reste modifiable.

## 4. Publier une refonte en lot

Dans **Versions**, cochez plusieurs versions (par exemple header, accueil et fiche produit), puis cliquez sur **Publier la sélection**, maintenant ou à une date. Tout est publié ou rien ne l'est.

## 5. Revenir en arrière

Sur la page, cliquez sur **Historique**. Chaque version précédemment en ligne peut être prévisualisée, comparée, puis remise en ligne avec **Revenir à cette version**. Ce retour est lui-même annulable.

## Couleurs des états

| Couleur | Signification |
|---|---|
| Bleu | Version de travail |
| Ambre | En attente du client |
| Vert | Validée par le client |
| Violet | Programmée |
| Rouge | L'original a changé (ou a été supprimé) |

## Bon à savoir

- **Classes globales, variables, couleurs, styles de thème :** leur modification est en ligne immédiatement, même depuis une version. Préférez créer de nouvelles classes.
- **Champs SCF :** pas encore versionnés, leurs modifications sont directement en ligne.
- **Snippets ou conditions basés sur l'ID de la page :** ils peuvent ne pas s'appliquer dans l'aperçu (la version a un autre ID). Tout redevient normal après publication.
- **« L'original a changé » :** quelqu'un a modifié la page directement pendant que la version était ouverte. Comparez, puis choisissez de publier quand même ou non.
