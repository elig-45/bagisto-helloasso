<?php

return [
    'environment' => 'Choose the same environment as your HelloAsso credentials: Sandbox for testing, Production for live payments.',
    'auth-mode' => 'Choose “Association API key” for credentials from My account > Integrations and API. Partner mode is only for HelloAsso partner applications.',
    'organization-slug' => 'On your public HelloAsso page, copy the part of the address after /associations/ (for example: helloasso.com/associations/my-organization). Partner mode fills it in after OAuth authorization.',
    'client-id' => 'In your HelloAsso account, go to My account > Integrations and API. Use the clientId for the selected environment. Partner mode uses your partner application credentials.',
    'client-secret' => 'In the same section as the Client ID: My account > Integrations and API. Enter the clientSecret paired with that Client ID and environment.',
    'webhook-signature' => 'Optional and for partners only. The HelloAsso API returns the signatureKey with the partner notification URL configuration.',
    'sepa' => 'This setting offers SEPA direct debit at checkout. Availability depends on the organization’s eligibility and permitted payment methods in HelloAsso.',
    'initial-percent' => 'Set the share of the total requested in the first payment when a customer chooses an installment plan.',
    'installments' => 'Select the plans to offer customers. These are store settings; HelloAsso applies its own schedule constraints.',
    'order-policy' => 'Choose when a verified order becomes ready for processing. SEPA payments split across installments remain pending until the full amount is paid.',
    'cancel-future-terms' => 'Choose whether upcoming HelloAsso installments should be canceled when a full installment refund is confirmed.',
];
