<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Hub mail theme (#4661): every email the Hub sends goes out with the Hub's header, colours
 * and footer, as HTML and plain text, and never mentions Laravel.
 */
class MailThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.name' => 'Project Trucks', 'app.url' => 'https://hub.example.test', 'mail.default' => 'array']);
    }

    /** Sends for real through the array mailer and returns what left. */
    private function send(User $user, Notification $notification): Email
    {
        $user->notify($notification);
        /** @var SentMessage|null $sent */
        $sent = app('mailer')->getSymfonyTransport()->messages()->last();
        $this->assertNotNull($sent, 'no mail was sent');
        $email = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function assertBranded(Email $email): void
    {
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        $this->assertNotSame('', $text, 'plain-text part missing');
        foreach ([$html, $text] as $body) {
            $this->assertStringNotContainsStringIgnoringCase('laravel', $body);
            $this->assertStringContainsString('Project Trucks', $body);
            $this->assertStringContainsString('The companion site for the ProjectTrucks desktop app.', $body);
        }
        // Header band, icon and amber button, inlined (mail clients drop <style>).
        $this->assertStringContainsString('https://hub.example.test/apple-touch-icon.png', $html);
        $this->assertMatchesRegularExpression('/class="header"[^>]*style="[^"]*background-color: #0f172a/', $html);
        $this->assertMatchesRegularExpression('/class="button button-primary"[^>]*style="[^"]*background-color: #f59e0b/', $html);
        $this->assertStringContainsString('hub.example.test</a>', $html);
        $this->assertStringContainsString('Drive safe,', $html);
    }

    public function test_verify_email_uses_the_hub_theme_and_wording(): void
    {
        $user = User::factory()->unverified()->create();

        $email = $this->send($user, new VerifyEmail);

        $this->assertSame('Confirm your email for Project Trucks', $email->getSubject());
        $this->assertBranded($email);
        $this->assertStringContainsString('Confirm email address', (string) $email->getHtmlBody());
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', (string) $email->getTextBody());
    }

    public function test_reset_password_uses_the_hub_theme_and_a_working_link(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $email = $this->send($user, new ResetPassword('tok123'));

        $this->assertSame('Set a new password for Project Trucks', $email->getSubject());
        $this->assertBranded($email);
        $text = (string) $email->getTextBody();
        // Same link as Laravel's own ResetPassword mail (Fortify's reset page).
        $this->assertStringContainsString(url('/reset-password/tok123').'?email=reset%40example.com', $text);
        $this->assertStringContainsString('This link works for 60 minutes.', $text);
    }

    public function test_hub_notices_use_the_same_theme(): void
    {
        $user = User::factory()->create();

        $email = $this->send($user, HubNotice::appLinked('Test PC'));

        $this->assertBranded($email);
        $this->assertStringContainsString('Settings → Notifications', (string) $email->getTextBody());
    }
}
