<?php

namespace App\Http\Controllers\Web\Site;

use App\Http\Controllers\Controller;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * The platform's landing page.
 *
 * Written for doctors deciding whether to use the platform; they reach the
 * team directly by WhatsApp, phone or email. Patients never need it: they
 * reach their doctor through the doctor's own page.
 */
class SiteController extends Controller
{
    public function home(): View
    {
        App::setLocale(config('clinic.api.default_locale'));

        $whatsapp = $this->dialable(config('clinic.site.whatsapp'));
        $phone = $this->dialable(config('clinic.site.phone'));

        return view('site.home', [
            // wa.me wants the number with its country code and nothing else.
            'whatsappLink' => $whatsapp === null
                ? null
                : 'https://wa.me/'.ltrim($whatsapp->e164, '+').'?text='.rawurlencode(__('site.contact.whatsapp_greeting')),
            'whatsappNational' => $whatsapp?->national(),
            'phone' => $phone,
            'email' => config('clinic.site.email') ?: null,
            'social' => config('clinic.site.social', []),
            'screens' => config('clinic.site.screens', []),
        ]);
    }

    private function dialable(?string $number): ?PhoneNumber
    {
        return blank($number) ? null : PhoneNumber::tryParse($number);
    }
}
