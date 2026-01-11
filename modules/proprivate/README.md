# ProPrivate (PrestaShop 9) — Boutique professionnelle privée

## Objectif
- Boutique PRO inaccessible aux visiteurs non connectés
- Crawlers -> HTTP 403 + `X-Robots-Tag: noindex, nofollow`
- Visiteurs "humains" -> redirection vers /connexion (ou 403 si désactivé)
- Clients connectés -> autorisés uniquement s'ils sont dans le(s) groupe(s) configuré(s) (ex: groupe "pro")

## Valeurs par défaut fournies
- Domaine/host: `pro.instinct-ad.fr`
- Boutique ID: `2`
- Chemins autorisés:
  - /connexion
  - /mot-de-passe-oublie
  - /reset-mot-de-passe*
  - /mentions-legales
  - /module/*

⚠️ Après installation, allez dans Modules > ProPrivate > Configurer et sélectionnez le groupe `pro` dans "Groupes clients autorisés".

## Multi-boutique
Pour surcharger des valeurs par boutique, mettez le contexte du BO sur la boutique concernée puis enregistrez.

## Thème
Aucune modification de thème n'est nécessaire : blocage via Subscriber Symfony (réponses 403 / redirections).
