<?php

return [
    'environment' => 'Choisissez le même environnement que celui des identifiants HelloAsso : Sandbox pour les essais, Production pour l’encaissement réel.',
    'auth-mode' => 'Choisissez « Clé API de l’association » pour les identifiants de Mon compte > Intégration et API. Le mode partenaire est réservé aux applications partenaires HelloAsso.',
    'organization-slug' => 'Sur la page publique HelloAsso, copiez le segment de l’adresse après /associations/ (par exemple : helloasso.com/associations/mon-association). En mode partenaire, il est renseigné après la connexion OAuth.',
    'client-id' => 'Dans votre compte HelloAsso : Mon compte > Intégration et API. Utilisez le clientId correspondant à l’environnement choisi. En mode partenaire, utilisez les identifiants de votre application partenaire.',
    'client-secret' => 'Dans la même section que le Client ID : Mon compte > Intégration et API. Saisissez le clientSecret associé au Client ID et au même environnement.',
    'webhook-signature' => 'Facultative et réservée aux partenaires. La signatureKey est renvoyée avec la configuration de l’URL de notification partenaire par l’API HelloAsso.',
    'sepa' => 'Ce réglage propose le prélèvement SEPA au paiement. Son acceptation dépend de l’éligibilité et des moyens de paiement autorisés pour l’association dans HelloAsso.',
    'initial-percent' => 'Définissez ici la part du total demandée au premier paiement lorsqu’un client choisit un plan en plusieurs fois.',
    'installments' => 'Cochez les plans à proposer au client. Ce sont des choix de votre boutique ; HelloAsso applique ses propres contraintes de calendrier.',
    'order-policy' => 'Choisissez à quel moment une commande vérifiée devient traitable. Les paiements SEPA en plusieurs échéances restent en attente du règlement total.',
    'cancel-future-terms' => 'Déterminez si les échéances HelloAsso à venir doivent être annulées quand le remboursement complet d’une échéance est confirmé.',
];
