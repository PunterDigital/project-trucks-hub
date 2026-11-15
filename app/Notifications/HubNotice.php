<?php

namespace App\Notifications;

use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every Hub notification (#4643): one class, a `NoticeType` and the text worked out when it
 * is sent. It holds only strings, never models, so a queued email still sends after the
 * application, membership or company it was about has gone.
 *
 * The bell (database channel) is written at once on the `sync` connection; emails go through
 * the queue, which needs a queue worker on the server.
 */
class HubNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $mailLines extra paragraphs for the email only */
    public function __construct(
        public NoticeType $notice,
        public string $message,
        public string $url,
        public string $subject,
        public array $mailLines = [],
        public string $actionText = 'Open the Hub',
    ) {}

    public static function applicationReceived(Company $company, User $applicant, ?string $note): self
    {
        $lines = $note !== null && $note !== '' ? ['Their message: “'.$note.'”'] : [];

        return new self(
            NoticeType::ApplicationReceived,
            __(':name applied to join :company.', ['name' => $applicant->name, 'company' => $company->name]),
            route('companies.show', $company),
            __('New application to :company', ['company' => $company->name]),
            [...$lines, __('Accept or decline it on the company page.')],
            __('Review the application'),
        );
    }

    public static function applicationDecided(Company $company, bool $accepted): self
    {
        return new self(
            NoticeType::ApplicationDecided,
            $accepted
                ? __(':company accepted your application. Welcome aboard!', ['company' => $company->name])
                : __(':company declined your application.', ['company' => $company->name]),
            route('companies.show', $company),
            $accepted
                ? __('You joined :company', ['company' => $company->name])
                : __('Your application to :company', ['company' => $company->name]),
            $accepted ? [__('Your new jobs now count towards the company’s totals.')] : [],
            __('Open the company'),
        );
    }

    public static function memberJoined(Company $company, User $member): self
    {
        return new self(
            NoticeType::MemberJoined,
            __(':name joined :company.', ['name' => $member->name, 'company' => $company->name]),
            route('companies.show', $company),
            __(':name joined :company', ['name' => $member->name, 'company' => $company->name]),
        );
    }

    public static function removedFromCompany(Company $company): self
    {
        return new self(
            NoticeType::RemovedFromCompany,
            __('You were removed from :company.', ['company' => $company->name]),
            route('companies.index'),
            __('You were removed from :company', ['company' => $company->name]),
        );
    }

    public static function roleChanged(Company $company, CompanyRole $role): self
    {
        return new self(
            NoticeType::RoleChanged,
            __('You are now :role at :company.', ['role' => strtolower($role->label()), 'company' => $company->name]),
            route('companies.show', $company),
            __('Your role at :company changed', ['company' => $company->name]),
        );
    }

    public static function ownershipHandedOver(Company $company, User $from): self
    {
        return new self(
            NoticeType::OwnershipHandedOver,
            __(':name handed :company over to you: you are now the owner.', ['name' => $from->name, 'company' => $company->name]),
            route('companies.show', $company),
            __('You now own :company', ['company' => $company->name]),
        );
    }

    public static function appLinked(string $clientName): self
    {
        return new self(
            NoticeType::AppLinked,
            __('“:app” was linked to your account and can now sync jobs.', ['app' => $clientName]),
            route('devices.index'),
            __('A ProjectTrucks app was linked to your account'),
            [__('If this wasn’t you, unlink it under Settings → Linked apps and change your password.')],
            __('See linked apps'),
        );
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        if ($notifiable->isSuspended()) {
            return [];
        }

        return $this->notice->emails() && $notifiable->wantsMail($this->notice)
            ? ['database', 'mail']
            : ['database'];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /** @return array{type: string, message: string, url: string} */
    public function toArray(User $notifiable): array
    {
        return ['type' => $this->notice->value, 'message' => $this->message, 'url' => $this->url];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->message);
        foreach ($this->mailLines as $line) {
            $mail->line($line);
        }

        return $mail
            ->action($this->actionText, $this->url)
            ->line(__('You can switch these emails off under Settings → Notifications.'));
    }
}
