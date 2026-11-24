<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Whether a request is a machine fetching the page rather than a person
 * opening it.
 *
 * Meta's crawler (facebookexternalhit) opens the links in WhatsApp messages
 * as they are sent: over three days of production logs (Nov 2026) it was the
 * only visitor on about one tracking link in five. Counted as a patient
 * opening their link, it would make the open rate look far better than it
 * is. In-app browsers (Facebook's FBAN/FB_IAB, WhatsApp opening a link) are
 * people and are not matched.
 */
final class LinkPreviewBot
{
    // Named crawlers, then the shapes they share ("bot" as a word, a
    // "+http://" contact link, scripting clients). Not a bare "bot" suffix:
    // CUBOT is a phone brand.
    private const PATTERN = '/facebookexternalhit|facebot|whatsapp\\/|telegrambot|twitterbot|slackbot|discordbot|linkedinbot|skypeuripreview|googlebot|bingbot|applebot|yandex|baiduspider|duckduckbot|petalbot|ahrefsbot|semrushbot|\\bbot\\b|crawler|spider|\\+https?:\\/\\/|curl\\/|wget\\/|python-|go-http-client|headlesschrome/i';

    public static function is(Request $request): bool
    {
        // A HEAD fetch checks the link without anybody looking at the page.
        if ($request->isMethod('HEAD')) {
            return true;
        }

        $agent = (string) $request->userAgent();

        return $agent === '' || preg_match(self::PATTERN, $agent) === 1;
    }
}
