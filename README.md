<a id="readme-top"></a>

<div align="center">
  <img src="https://upload.wikimedia.org/wikipedia/fr/2/27/Logo-HelloAsso.svg" alt="HelloAsso" width="96" height="96">
  <h1>Bagisto HelloAsso</h1>
  <p>Une intégration HelloAsso configurable par canal pour les commandes Bagisto.</p>
  <p>
    <a href="https://github.com/elig-45/bagisto-helloasso/issues/new?template=bug_report.yml">Signaler un problème</a>
    ·
    <a href="https://github.com/elig-45/bagisto-helloasso/issues/new?template=feature_request.yml">Proposer une évolution</a>
  </p>
</div>

[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Bagisto](https://img.shields.io/badge/Bagisto-2.4.x-0E9F6E)](https://bagisto.com/)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Licence AGPL-3.0](https://img.shields.io/badge/Licence-AGPL--3.0-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-Pest-6C2EB9)](tests/)

## Table des matières

- [À propos](#à-propos)
- [Compatibilité et prérequis](#compatibilité-et-prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Paiement et rapprochement](#paiement-et-rapprochement)
- [Remboursements et annulations](#remboursements-et-annulations)
- [Développement et tests](#développement-et-tests)
- [Périmètre et prochaines étapes](#périmètre-et-prochaines-étapes)
- [Contribuer](#contribuer)
- [Licence](#licence)
- [Remerciements](#remerciements)

## À propos

Bagisto HelloAsso ajoute HelloAsso Checkout aux moyens de paiement de [Bagisto](https://bagisto.com/). Le package utilise le client HTTP Laravel, sans SDK HelloAsso supplémentaire. Chaque canal dispose de sa propre connexion et de ses propres réglages.

Fonctionnalités principales :

- Paiement par carte, SEPA optionnel et échéanciers configurables de 2 à 12 paiements.
- Connexion par clé API d’association ou OAuth partenaire avec PKCE.
- Environnements sandbox et production, avec test de connexion et de capacité d’encaissement.
- Validation serveur auprès de HelloAsso, notifications `Order` et `Payment`, rapprochement et déduplication des événements.
- Création idempotente des commandes et factures selon la règle de confirmation choisie.
- Suivi des paiements, échéances, refus, contestations et remboursements dans l’administration de la commande.
- Remboursement et annulation par API lorsque les droits le permettent ; sinon, le package indique la démarche à effectuer dans HelloAsso.

Ce package couvre le règlement de commandes Bagisto en euros. Les campagnes, la billetterie, les adhésions et les dons ne sont pas dans son périmètre.

### Technologies

- [Bagisto 2.4](https://bagisto.com/) et [Laravel 12](https://laravel.com/)
- PHP 8.3 ou 8.4
- HTTP Client Laravel pour les API HelloAsso
- [Pest](https://pestphp.com/) pour les tests

## Compatibilité et prérequis

- Bagisto 2.4.x sur Laravel 12.
- PHP 8.3 ou 8.4 avec les extensions requises par Bagisto.
- Une clé `APP_KEY` définie et stable : les secrets, jetons et instantanés de panier temporaires sont chiffrés avec cette clé.
- Un accès HTTPS sortant vers l’API HelloAsso, ainsi qu’un planificateur Laravel actif pour le rapprochement périodique.
- Un compte d’association HelloAsso autorisé à encaisser, ou un compte partenaire configuré pour OAuth.

## Installation

Depuis la racine de votre boutique Bagisto :

```sh
composer require elig45/bagisto-helloasso:^1.0
php artisan migrate
php artisan vendor:publish --tag=helloasso-assets --force
php artisan optimize:clear
```

Laravel découvre automatiquement le fournisseur de services du package. Dans l’administration, ouvrez **HelloAsso** pour configurer la connexion du canal. Activez ensuite la méthode une seule fois dans **Configuration → Ventes → Moyens de paiement** ; c’est l’interrupteur d’activation unique de Bagisto.

## Configuration

Les réglages et les identifiants sont indépendants pour chaque canal Bagisto.

Pour bloquer l’encaissement réel dans un environnement de recette, définissez `HELLOASSO_ALLOW_PRODUCTION=false`. Le mode sandbox reste disponible ; le contrôle est appliqué au checkout même si un administrateur choisit Production dans les réglages.

### Connexion de l’association

Choisissez **Clé API de l’association**, puis définissez le mode, le slug de l’association, le Client ID et le Client Secret fournis par HelloAsso. Les secrets et jetons sont chiffrés en base. Le test confirme séparément l’accès API et l’éligibilité d’encaissement ; certains rôles HelloAsso ne donnent pas accès au champ d’éligibilité, qui est alors signalé comme inconnu. Un retour d’API qui refuse le Checkout reste déterminant.

### Connexion partenaire

Choisissez **OAuth partenaire**, saisissez les identifiants du client partenaire puis utilisez **Connecter une association**. Le domaine de la boutique et son URL HTTPS de rappel doivent être autorisés dans le client API HelloAsso. L’association sélectionnée pendant OAuth est liée au canal.

### Moyens de paiement et échéanciers

- Commencez en **sandbox** avec les identifiants et une association de test HelloAsso.
- La carte est proposée par défaut. Le SEPA peut être activé si l’association l’autorise ; son statut peut rester en attente plusieurs jours.
- Activez les échéanciers proposés et réglez leur premier versement. Les dates suivent les contraintes HelloAsso : au plus un versement mensuel, au plus le 27, avec un maximum de 12 versements.
- Choisissez quand Bagisto rend la commande traitable : après le premier paiement autorisé, après autorisation du montant total ou après vérification du résultat distant avec une commande en attente.
- Un prélèvement SEPA en validation bancaire crée une commande Bagisto en attente de paiement. Pour un échéancier SEPA, la commande reste en attente et la facture n’est marquée payée qu’après l’autorisation de toutes les échéances.

Les montants sont calculés à partir du total du panier et transmis à HelloAsso en euros. L’intention de paiement, le canal, le plan et le montant attendu sont conservés pour sécuriser le rapprochement.

## Paiement et rapprochement

Dans les réglages du canal, copiez l’URL de notification affichée vers HelloAsso et activez les événements `Order`, `Payment` et `Organization` si votre compte les propose. Le dernier événement actualise l’éligibilité connue de l’association. Pour une connexion partenaire, ajoutez la clé de signature de notification afin de vérifier les notifications HMAC-SHA256.

Ajoutez le planificateur Laravel à la configuration de la boutique :

```cron
* * * * * cd /chemin/vers/bagisto && php artisan schedule:run >> /dev/null 2>&1
```

Les intentions en attente sont rapprochées périodiquement. Les commandes suivantes sont également disponibles pour lancer des opérations manuellement :

```sh
php artisan helloasso:reconcile --limit=100
php artisan helloasso:refresh-tokens
```

Un retour navigateur ne confirme jamais un paiement à lui seul. Au retour client, à la réception des notifications et lors du rapprochement, le package vérifie l’état auprès de l’API HelloAsso avant de créer ou modifier la commande. Les contrôles incluent le canal, l’association, la devise, le montant et l’intention de paiement.

## Remboursements et annulations

La possibilité de rembourser ou d’annuler une échéance dépend des droits HelloAsso du compte. Le package tente le remboursement intégral par clé d’association lorsque l’API l’autorise ; HelloAsso a annoncé le retrait de cet endpoint pour les clés d’association. Les remboursements partiels avec ce mode restent à réaliser dans HelloAsso. Si HelloAsso refuse une opération ou si l’API ne la prend plus en charge, le package n’enregistre pas de remboursement Bagisto et invite l’administrateur à vérifier le statut distant. Un remboursement local n’est marqué comme effectué qu’après confirmation distante du statut `Processed` ; les opérations réalisées dans HelloAsso peuvent ensuite être synchronisées dans Bagisto.

## Développement et tests

```sh
composer install
composer validate --strict
composer test
composer lint
composer check-translations
```

Les tests couvrent notamment l’authentification et le renouvellement des jetons, les montants, les échéanciers, les retours non fiables, les notifications répétées et les 22 catalogues de traduction.

Pour une recette complète, utilisez des identifiants sandbox, configurez les notifications HelloAsso et vérifiez l’accès réseau ainsi que l’exécution régulière du planificateur.

### Proxy réseau

Pour contrôler les connexions sortantes, configurez `HELLOASSO_HTTP_PROXY` vers un proxy HTTPS avec liste d’autorisation limitée aux domaines HelloAsso. Les appels API, l’authentification et OAuth utilisent ce proxy.

## Périmètre et prochaines étapes

- [x] Paiements de commandes Bagisto par carte, SEPA optionnel et échéancier.
- [x] Connexion par association et OAuth partenaire, sandbox et production.
- [x] Validation distante, notifications, déduplication et rapprochement.
- [x] Suivi des échéances et synchronisation des remboursements.
- [ ] Recette de bout en bout sur une boutique Bagisto avec identifiants sandbox et notifications d’association configurées.
- [ ] Étendre les traductions au-delà du français et de l’anglais.

Les changements et anomalies peuvent être suivis dans les [issues du dépôt](https://github.com/elig-45/bagisto-helloasso/issues).

## Contribuer

Les contributions sont les bienvenues. Avant d’ouvrir une pull request, lancez les vérifications du package :

```sh
composer validate --strict
composer test
composer lint
composer check-translations
```

Pour une évolution importante, ouvrez d’abord une issue afin de discuter du besoin et de sa compatibilité avec Bagisto.

## Licence

Distribué sous licence GNU Affero General Public License v3.0 uniquement. Consultez [LICENSE](LICENSE).

## Remerciements

- [Documentation API HelloAsso](https://dev.helloasso.com/docs)
- [Best-README-Template](https://github.com/othneildrew/Best-README-Template), dont la structure a inspiré ce README.
- Les mainteneurs et contributeurs de [Bagisto](https://github.com/bagisto/bagisto) et [Laravel](https://github.com/laravel/laravel).

<p align="right"><a href="#readme-top">Retour en haut ↑</a></p>
