<?php

return [
    'mode' => 'audit_and_approval',
    'actions' => [
        [
            'key' => 'website_calls',
            'name' => 'BMT | Website Calls',
            'purpose' => 'Track eligible calls generated from the website where supported by the configured Google Ads conversion setup.',
            'primary' => true,
            'approval_required' => true,
        ],
        [
            'key' => 'click_to_call',
            'name' => 'BMT | Click to Call',
            'purpose' => 'Track taps/clicks on the website telephone link as an intent signal, not proof of a completed call.',
            'primary' => false,
            'approval_required' => true,
        ],
        [
            'key' => 'whatsapp_contact',
            'name' => 'BMT | WhatsApp Contact',
            'purpose' => 'Track website WhatsApp contact initiation. A click is not treated as proof that a message was sent.',
            'primary' => false,
            'approval_required' => true,
        ],
        [
            'key' => 'lead_form',
            'name' => 'BMT | Lead Form Submitted',
            'purpose' => 'Track successful website lead form submissions.',
            'primary' => true,
            'approval_required' => true,
        ],
    ],
    'automatic_creation' => false,
    'duplicate_check_required' => true,
    'production_activation_required' => true,
];
