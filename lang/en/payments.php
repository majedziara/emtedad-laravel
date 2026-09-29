<?php

return [
    'idempotency_conflict' => 'This key belongs to a different request. Replay the original request or use a new key for a new donation.',
    'environment_conflict' => 'This payment belongs to another environment.',
    'case_not_accepting' => 'This case is not accepting new donations.',
    'unsupported_currency' => 'This case currency is not enabled for PayPal.',
    'invalid_webhook' => 'Invalid PayPal webhook.',
    'invalid_signature' => 'PayPal signature verification failed.',
    'webhook_received' => 'Webhook accepted for processing.',
    'return_received' => 'Returned from PayPal. Check the donation API; a browser return does not confirm payment.',
    'cancel_received' => 'Returned from checkout. Donation status was not changed; check it using the API.',
    'gateway_error' => 'Unable to complete this payment step. Check donation status before retrying.',
];
