<?php

/*
| The alarm for verification codes that do not reach patients: the mail.
*/

return [
    'mail' => [
        'subject' => '⚠️ Elayadah: verification codes are not reaching patients',
        'lead' => 'Patients are asking for a verification code when booking and nobody types it, which usually means the messages are not arriving.',
        'since' => 'Since: :time (Cairo time)',
        'counts' => ':codes codes to :phones numbers, none typed.',
        'refused' => ':count of them were refused by the SMS provider outright.',
        'clinic' => ':name: :codes codes',
        'last_typed' => 'Last code typed: :time',
        'what_to_do' => 'Check the SMS provider\'s dashboard and contact them. If it continues, turn off the code request on the system settings page so patients can book.',
        'open_settings' => 'Open system settings',
        'footer' => 'No further mail until codes work again.',

        'recovered_subject' => '✅ Elayadah: verification codes are arriving again',
        'recovered_lead' => 'A patient typed a code at :time, so messages are arriving again.',
        'recovered_since' => 'The problem started at :time.',
        'recovered_check' => 'If you turned off the code request during the problem, you can turn it back on from the system settings page.',
    ],
];
