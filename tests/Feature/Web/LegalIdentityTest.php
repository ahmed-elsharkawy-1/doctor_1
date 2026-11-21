<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meta Business Verification needs the operating company's legal name in the
 * server-rendered HTML of the public site, exactly as registered.
 */
class LegalIdentityTest extends TestCase
{
    use RefreshDatabase;

    public static function pages(): array
    {
        return [
            'home' => ['/'],
            'home in English' => ['/?lang=en'],
            'privacy' => ['/privacy'],
            'privacy in English' => ['/privacy?lang=en'],
        ];
    }

    /** @dataProvider pages */
    public function test_every_public_page_names_the_operating_company(string $url): void
    {
        $this->get($url)
            ->assertOk()
            ->assertSee('operated by Hub Works', escape: false)
            ->assertSee('هب ووركس للبرمجيات', escape: false)
            ->assertSee('saad@elayadah.com', escape: false)
            ->assertSee('+20 100 160 0581', escape: false);
    }

    public function test_the_privacy_policy_says_who_operates_the_site(): void
    {
        $this->get('/privacy')->assertSeeText('هذا الموقع تديره Hub Works (هب ووركس للبرمجيات).');
        $this->get('/privacy?lang=en')->assertSeeText('This website is operated by Hub Works (هب ووركس للبرمجيات).');
    }
}
