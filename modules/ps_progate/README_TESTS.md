# Tests du module ps_progate

Ce module inclut des **tests unitaires** (rapides, sans PrestaShop) et des **tests d’intégration** (qui nécessitent une installation PrestaShop et/ou un container Symfony compilable).

## Pré-requis

- PHP compatible avec ta version de PrestaShop (ex: PHP 8.1+ pour PS9)
- Composer

Depuis le dossier du module :

```bash
cd modules/ps_progate
composer install
```

> Astuce : si tu exécutes les tests dans un conteneur, lance `composer install` *dans* le conteneur.

## Lancer les tests

### 1) Unit (par défaut)

```bash
vendor/bin/phpunit
```

ou explicitement :

```bash
vendor/bin/phpunit --testsuite unit
```

### 2) Integration (optionnel)

Les tests d’intégration sont **désactivés par défaut** (ils dépendent de l’environnement).

```bash
vendor/bin/phpunit --testsuite integration
```

### 3) Tout exécuter

```bash
vendor/bin/phpunit --testsuite all
```

## Organisation des tests

- `tests/Unit/` : tests unitaires (sans bootstrap PrestaShop).
- `tests/Integration/` : tests d’intégration (DI container, constantes PrestaShop, etc.).

## Variables utiles

Tu peux forcer certains comportements via des variables d’environnement :

- `PS_PROGATE_TESTS_INTEGRATION=1` : autorise l’exécution de certains tests d’intégration qui seraient sinon *skipped*.

> Les tests d’intégration **doivent** être stables : s’il manque le bootstrap PrestaShop, ils passent en *skipped* (pas en échec).

## Dépannage

### Sorties parasites dans le bootstrap

Le bootstrap de tests **ne doit pas faire de `echo`** (sinon ça casse les assertions HTTP/headers dans certains tests). Si tu ajoutes du debug, utilise `error_log()`.

### Lancer un seul test

```bash
vendor/bin/phpunit --filter AccessGateTest
```

