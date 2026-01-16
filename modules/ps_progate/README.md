# ProGate - Module PrestaShop 9.0.1

## Description

Module de boutique PRO privée avec validation commerciale pour PrestaShop 9.0.1. Permet de restreindre l'accès à votre boutique professionnelle uniquement aux clients validés commercialement.

## Fonctionnalités

### 🔒 Boutique PRO Privée (Multi-boutique)
- **Visiteur non connecté** : Redirection automatique vers la page de connexion
- **Nouveau client** : Peut s'inscrire normalement sur la boutique PRO
- **Statut "Pending Validation"** : Les nouveaux clients sont automatiquement assignés à un groupe en attente de validation
- **Client PRO validé** : Accès complet au catalogue, panier et commande

### 🎯 Whitelist de Chemins (Allowed Paths)
Configuration flexible des URLs accessibles sans authentification :
- `/authentication` - Page de connexion
- `/password` - Récupération de mot de passe
- `/module/ps_progate/pending` - Page d'information validation en cours
- `/contact` - Page de contact
- `/cms` - Pages CMS
- `/logout` - Déconnexion

### 🤖 Sécurité Bots/Humains
- **PS_PROGATE_BOTS_403** : Retourne HTTP 403 aux bots au lieu d'une redirection
- **PS_PROGATE_HUMANS_REDIRECT** : URL de redirection personnalisée pour les visiteurs humains non connectés

### 🏪 Ciblage Shop / Domaine
- **PS_PROGATE_ENABLED** : Active (1)/désactive (0) le mode privé par boutique
- **PS_PROGATE_SHOP_IDS** : Liste CSV des IDs de boutiques ciblées (vide = boutique courante uniquement)
- **PS_PROGATE_HOSTS** : Liste CSV des domaines autorisés (ex: pro.instinct-ad.fr)

### 👥 Gestion des Groupes
- **PS_PROGATE_ALLOWED_GROUPS** : IDs des groupes clients avec accès complet (PRO)
- **PS_PROGATE_PENDING_GROUP** : ID du groupe assigné aux nouveaux inscrits en attente de validation

## Installation

1. **Télécharger le module** : Téléchargez le fichier ZIP `ps_progate.zip`
2. **Installer via le Back-Office** :
   - Allez dans `Modules` > `Module Manager`
   - Cliquez sur `Uploader un module`
   - Sélectionnez le fichier ZIP
   - Cliquez sur `Installer`

## Configuration

### Accéder à la configuration

1. Allez dans `Modules` > `Module Manager`
2. Recherchez "ProGate" ou "Pro Private Shop"
3. Cliquez sur `Configurer`

### Paramètres disponibles

#### Mode Privé
- **Enable Private Mode** : Active/désactive le mode privé pour la boutique courante

#### Ciblage
- **Target Shop IDs** : IDs des boutiques ciblées séparés par virgules (ex: 1,2,3)
  - Laissez vide pour appliquer uniquement à la boutique courante
- **Allowed Hosts** : Domaines autorisés séparés par virgules (ex: pro.instinct-ad.fr,b2b.example.com)
  - Laissez vide pour autoriser tous les domaines

#### Whitelist de Chemins
- **Allowed Paths (Whitelist)** : Un préfixe de chemin par ligne
  - Ces chemins sont accessibles sans authentification
  - Exemple : `/authentication`, `/contact`, `/cms`

#### Groupes Clients
- **Allowed Groups (PRO)** : IDs des groupes avec accès complet séparés par virgules
  - Exemple : `4,5,6`
- **Pending Validation Group** : Groupe assigné automatiquement aux nouveaux inscrits
  - Sélectionnez un groupe existant dans la liste déroulante

#### Sécurité
- **Bots: Return 403** : Si activé, les bots reçoivent une erreur HTTP 403 au lieu d'une redirection
- **Humans Redirect URL** : URL absolue de redirection pour les visiteurs non connectés
  - Laissez vide pour utiliser la page de connexion standard

## Architecture Technique

### Compatibilité PrestaShop 9.0.1

Le module est conçu pour fonctionner sur les deux cycles de PrestaShop :

#### Cycle Legacy FO
- **Hook** : `actionFrontControllerInitBefore`
- Applique les règles d'accès sur les contrôleurs FO legacy

#### Cycle Symfony
- **Event Subscriber** : `FrontAccessSubscriber`
- S'abonne à `kernel.request` avec priorité 20
- CLI-safe : Ignore les commandes CLI, cache:clear, cache:warmup, cron
- Ignore le Back-Office (firewall admin + routes admin_*)
- Ignore les routes système (_profiler, _wdt)

### Structure des Fichiers

```
ps_progate/
├── config/
│   ├── services.yml           # Import des services
│   └── services.php           # Déclaration des services Symfony
├── controllers/
│   └── front/
│       └── pending.php        # Contrôleur page validation en cours
├── src/
│   ├── EventSubscriber/
│   │   └── FrontAccessSubscriber.php  # Subscriber Symfony
│   └── Service/
│       └── AccessGate.php     # Service logique métier
├── vendor/
│   └── autoload.php           # Autoloader PSR-4 minimal
├── views/
│   └── templates/
│       └── front/
│           └── pending.tpl    # Template page validation
├── composer.json              # Configuration Composer
├── ps_progate.php            # Classe principale du module
└── README.md                 # Ce fichier
```

### Services

#### AccessGate Service
- **ID** : `ps_progate.service.access_gate`
- **Responsabilité** : Logique métier de contrôle d'accès
- **Méthodes** :
  - `enforceLegacy()` : Applique le gate sur les contrôleurs legacy
  - `enforceSymfony(Request)` : Applique le gate sur les routes Symfony

#### FrontAccessSubscriber
- **Tag** : `kernel.event_subscriber`
- **Événement** : `kernel.request` (priorité 20)
- **Responsabilité** : Intercepte les requêtes Symfony et applique les règles d'accès

## Workflow Client

### 1. Inscription d'un nouveau client
1. Le visiteur accède à `/authentication` (whitelist)
2. Il crée un compte sur la boutique PRO
3. **Hook `actionCustomerAccountAdd`** : Le module assigne automatiquement le groupe "PENDING"
4. Le client reçoit un email de confirmation d'inscription

### 2. Connexion avant validation
1. Le client se connecte
2. Le système détecte qu'il n'est pas dans un groupe PRO autorisé
3. **Redirection** vers `/module/ps_progate/pending`
4. Affichage du message : "Validation commerciale en cours"

### 3. Validation commerciale
1. L'administrateur valide le compte dans le Back-Office
2. Il assigne le client à un groupe PRO autorisé (ex: "Professionnels", "B2B")
3. Il retire le client du groupe "PENDING"

### 4. Accès complet
1. Le client se connecte
2. Le système détecte qu'il est dans un groupe PRO autorisé
3. **Accès complet** au catalogue, panier, commande

## Détection des Bots

Le module détecte automatiquement les bots grâce aux patterns User-Agent :
- `bot`, `crawl`, `spider`, `slurp`, `mediapartners`
- `ahrefs`, `semrush`, `moz`, `majestic`, `yandex`
- `baidu`, `duckduck`, `bingpreview`
- `facebot`, `twitterbot`, `linkedinbot`
- `whatsapp`, `telegram`

## Support Multi-boutique

Le module est entièrement compatible multi-boutique :
- Chaque boutique a sa propre configuration
- Les configurations sont stockées par ID de boutique
- Vous pouvez activer le mode privé sur certaines boutiques uniquement
- Vous pouvez cibler des boutiques spécifiques avec `PS_PROGATE_SHOP_IDS`

## Cas d'Usage

### Boutique B2B Privée
```
- Enable Private Mode: Oui
- Allowed Groups: 4 (Professionnels)
- Pending Group: 5 (En attente validation)
- Bots Return 403: Oui
```

### Boutique PRO avec Domaine Dédié
```
- Enable Private Mode: Oui
- Allowed Hosts: pro.instinct-ad.fr
- Allowed Groups: 4,5,6
- Pending Group: 7
```

### Redirection Personnalisée
```
- Enable Private Mode: Oui
- Humans Redirect URL: https://www.example.com/info-pro
- Bots Return 403: Oui
```

## Dépannage

### Le module ne bloque pas l'accès
1. Vérifiez que "Enable Private Mode" est activé
2. Vérifiez que la boutique courante correspond aux "Target Shop IDs"
3. Vérifiez que le domaine correspond aux "Allowed Hosts"
4. Videz le cache PrestaShop

### Les clients validés sont redirigés
1. Vérifiez que le client est bien dans un groupe listé dans "Allowed Groups"
2. Vérifiez les IDs des groupes (Admin > Clients > Groupes)

### Erreur 500 après installation
1. Vérifiez les logs PHP
2. Videz le cache Symfony : `php bin/console cache:clear`
3. Vérifiez que le fichier `vendor/autoload.php` est présent

### Les commandes CLI ne fonctionnent plus
Le module est CLI-safe, il ne devrait pas impacter les commandes. Si problème :
1. Vérifiez que PHP_SAPI est bien détecté comme 'cli'
2. Désactivez temporairement le module

## Licence

MIT License

## Support

Pour toute question ou problème, contactez le support technique.

## Version

**1.0.0** - Compatible PrestaShop 9.0.1