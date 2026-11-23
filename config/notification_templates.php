<?php

return [
    'messages' => [
        'pledge_created_whatsapp' => 'Hello. Your pledge has been recorded. Your guest code is {manual_code}. Thank you.',
        'pledge_reminder_whatsapp' => 'Reminder: Please complete your pledge. Your guest code is {manual_code}. Thank you.',
        'pledge_completed_whatsapp' => 'Thank you for completing your pledge. Your guest code is {manual_code}. Please keep this code safe.',
        'next_meeting_whatsapp' => 'Reminder: The next meeting is scheduled for {meeting_date}. Venue: {meeting_venue}.',
        'digital_card_whatsapp' => 'Thank you for completing your payment. Your digital card is ready: {card_link}',
        'thank_you_whatsapp' => 'Thank you for your support. Your guest code is {manual_code}.',
        'congratulations_whatsapp' => 'Congratulations and thank you. Your contribution has been received successfully.',
        'guest_code_whatsapp' => 'Your guest verification code is {manual_code}. Please keep it safe for entrance verification.',
    ],

    'supported_placeholders' => [
        'manual_code',
        'phone_number',
        'event_name',
        'allowed_entries',
        'pledge_amount',
        'pledge_balance',
        'meeting_date',
        'meeting_venue',
        'card_link',
    ],

    'whatsapp_templates' => [
        'pledge_created' => env('WHATSAPP_TEMPLATE_PLEDGE_CREATED', 'pledge_created'),
        'pledge_reminder' => env('WHATSAPP_TEMPLATE_PLEDGE_REMINDER', 'pledge_reminder'),
        'pledge_completed' => env('WHATSAPP_TEMPLATE_PLEDGE_COMPLETED', 'pledge_completed'),
        'next_meeting' => env('WHATSAPP_TEMPLATE_NEXT_MEETING', 'next_meeting'),
        'digital_card_ready' => env('WHATSAPP_TEMPLATE_DIGITAL_CARD_READY', 'digital_card_ready'),
        'thank_you' => env('WHATSAPP_TEMPLATE_THANK_YOU', 'thank_you'),
        'congratulations' => env('WHATSAPP_TEMPLATE_CONGRATULATIONS', 'congratulations'),
        'guest_code' => env('WHATSAPP_TEMPLATE_GUEST_CODE', 'guest_code'),
    ],
];
