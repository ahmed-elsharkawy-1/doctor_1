<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Only one private channel exists, and it asks the same question the rest of
| the app asks everywhere else: does this user work at this clinic. Clinic
| scope is never taken from the client — here the clinic id arrives in the
| channel name, so it is checked against the user's own clinics rather than
| trusted.
|
| The patient booking page listens on a public channel (see App\Events\
| SlotsChanged) and never reaches this file: a stranger on a booking page has
| no account to authorise, and the event carries nothing about anybody.
|
*/

// Matches SelfBookingReceived::channelFor(), which is where the name is built.
Broadcast::channel(
    'clinic.{clinicId}',
    fn (User $user, int $clinicId): bool => $user->clinics()->whereKey($clinicId)->exists(),
);
