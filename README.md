# Bagisto HelloAsso

Méthode de paiement HelloAsso Checkout pour les boutiques Bagisto 2.4.x sous PHP 8.3 ou 8.4 et Laravel 12. Le package est indépendant par canal Bagisto et utilise le client HTTP Laravel, sans SDK HelloAsso tiers.

Version : `1.0.0` · Licence : MIT

## Fonctions

- Paiement en une fois, prélèvement SEPA optionnel et échéanciers de 2 à 12 paiements configurables par canal.
- Clés API d’association ou autorisation OAuth partenaire avec PKCE.
- Environnements sandbox et production, vérification de la connexion et de la capacité d’encaissement.
- Validation serveur par l’API HelloAsso, notifications `Order` et `Payment`, rapprochement planifié et déduplication des événements.
- Création idempotente des commandes et factures Bagisto selon la règle choisie : premier paiement autorisé, montant total autorisé ou résultat distant vérifié avec commande en attente.
- Suivi des paiements, échéances, refus, contestations et remboursements dans la commande.
- Remboursements et annulations distants lorsque l’API et les droits du compte le permettent. Une demande locale n’est jamais affichée comme un remboursement HelloAsso effectué.

Le package vise uniquement le paiement de commandes Bagisto en euros. Il ne gère pas les campagnes, la billetterie, les adhésions ni les dons.

## Installation

Dans une boutique Bagisto 2.4.x :

```sh
composer require elig45/bagisto-helloasso:^1.0
php artisan migrate
php artisan vendor:publish --tag=helloasso-assets --force
php artisan optimize:clear
```

Laravel découvre automatiquement le fournisseur de services du package. Vérifiez que `APP_KEY` est défini et conservé : les secrets, jetons et données temporaires de panier sont chiffrés avec cette clé.

## Configuration

Dans l’administration, ouvrez **HelloAsso** et sélectionnez le canal à configurer. Enregistrez une connexion distincte pour chaque canal. Activez ensuite aussi HelloAsso dans **Configuration → Ventes → Moyens de paiement** pour le canal correspondant ; Bagisto et la connexion HelloAsso doivent tous deux être actifs.

### Connexion d’association

Choisissez le mode **Clé API de l’association**, sélectionnez l’environnement et saisissez le slug de l’association, le Client ID et le Client Secret obtenus dans son espace HelloAsso. Les secrets et jetons sont chiffrés en base de données.

### Connexion partenaire

Choisissez **OAuth partenaire**, enregistrez le Client ID et le Client Secret du partenaire puis utilisez **Connecter une association**. Le domaine et l’URL HTTPS de rappel doivent être autorisés dans le client API HelloAsso. L’association choisie pendant la mire OAuth est enregistrée sur le canal.

### Options de paiement

- **Sandbox** est le mode par défaut. Utilisez des identifiants d’association sandbox et une association sandbox vérifiée pour les essais.
- Le prélèvement SEPA est proposé en plus de la carte lorsque l’association l’autorise. Une échéance SEPA peut rester en attente de validation bancaire pendant plusieurs jours ; choisissez la règle de commande en conséquence.
- Cochez les échéanciers autorisés et définissez la part initiale. Les échéances sont mensuelles, au plus une par mois, au plus le 27 et sur une période maximale de douze mois.
- La règle **premier paiement autorisé** permet de traiter la commande après son premier paiement accepté. **Montant total autorisé** attend l’encaissement complet. **Résultat distant vérifié** peut créer une commande avec facture en attente de paiement.

## Notifications et rapprochement

L’écran de configuration affiche l’URL de notification du canal. Dans HelloAsso, configurez les événements `Order` et `Payment` vers cette URL. Pour un compte partenaire, copiez la clé de signature de notification dans **Clé de signature des notifications** ; les notifications signées sont alors vérifiées avec HMAC-SHA256.

Ajoutez l’exécution du planificateur Laravel sur la boutique :

```cron
* * * * * cd /chemin/vers/bagisto && php artisan schedule:run >> /dev/null 2>&1
```

Le package rapproche les intentions en attente toutes les cinq minutes et synchronise les paiements et remboursements périodiquement. Les commandes sont également disponibles pour une exécution manuelle :

```sh
php artisan helloasso:reconcile --limit=100
php artisan helloasso:refresh-tokens
```

Les redirections du navigateur ne valident jamais un paiement à elles seules. Le retour client, les notifications et la tâche planifiée interrogent l’API HelloAsso avant toute création ou mise à jour d’une commande.

## Remboursements et annulations

Les remboursements par API dépendent du mode d’autorisation et des droits HelloAsso. L’API de remboursement par clé d’association étant dépréciée, le package indique le recours à l’espace HelloAsso lorsqu’une demande distante n’est pas autorisée. Le remboursement Bagisto n’est créé qu’après confirmation du statut `Processed` par HelloAsso. Synchronisez ensuite la commande pour importer les remboursements réalisés dans l’espace HelloAsso.

L’annulation des échéances futures est proposée sur les commandes échelonnées. Si HelloAsso refuse l’opération ou demande une vérification supplémentaire, vérifiez d’abord le résultat dans son espace avant de modifier la commande Bagisto.

## Sécurité et mise en production

- La sandbox est sélectionnée par défaut. Ne stockez pas de secrets dans le dépôt, les journaux ou les captures d’écran.
- Les paiements de production exigent des identifiants de production et une association autorisée à encaisser. Testez d’abord le parcours complet en sandbox, puis activez la production canal par canal.
- `cart_snapshot` est chiffré et supprimé après la création de commande ou l’expiration d’une tentative sans commande. Les réponses d’API conservées sont limitées aux informations de rapprochement et de paiement nécessaires.
- Le réseau de la boutique doit pouvoir joindre `api.helloasso.com` ou `api.helloasso-sandbox.com`. Le planificateur doit tourner régulièrement pour suivre les échéances, paiements SEPA et remboursements différés.

## Développement et tests

```sh
composer install
composer validate --strict
composer test
composer lint
composer check-translations
```

La CI vérifie PHP 8.3 et 8.4. Les tests couvrent le calcul des échéances, l’authentification et la rotation des jetons, la reprise après un `401`, la validation stricte des montants et la cohérence des 22 catalogues de traduction.

Pour une recette de bout en bout, utilisez une association HelloAsso sandbox vérifiée, activez les notifications de cette association et vérifiez que la boutique dispose d’une sortie réseau et d’un planificateur actif.
