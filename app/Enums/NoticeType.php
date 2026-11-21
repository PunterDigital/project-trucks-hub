<?php

namespace App\Enums;

/**
 * What a notification is about (#4643). Every type goes to the bell; the ones with
 * `emails()` also send an email unless the driver switched it off in Settings → Notifications
 * (`users.mail_opt_outs` holds those values).
 */
enum NoticeType: string
{
    case ApplicationReceived = 'application_received';
    case ApplicationDecided = 'application_decided';
    case MemberJoined = 'member_joined';
    case RemovedFromCompany = 'removed_from_company';
    case RoleChanged = 'role_changed';
    case OwnershipHandedOver = 'ownership_handed_over';
    case AppLinked = 'app_linked';
    case AchievementUnlocked = 'achievement_unlocked';
    case KudosReceived = 'kudos_received';
    case CommentReceived = 'comment_received';
    case EventCancelled = 'event_cancelled';
    case DiscordWebhookDisabled = 'discord_webhook_disabled';
    case RankPromoted = 'rank_promoted';
    case CompanyNews = 'company_news';

    public function emails(): bool
    {
        return in_array($this, [self::ApplicationReceived, self::ApplicationDecided, self::AppLinked], true);
    }

    /** @return list<self> */
    public static function emailed(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->emails()));
    }

    /**
     * Bell notices a driver can switch off (#5224; `users.bell_opt_outs` holds those values).
     * The rest always show: they are about the driver's own account, jobs or applications.
     */
    public function bellOptional(): bool
    {
        return $this === self::CompanyNews;
    }

    /** @return list<self> */
    public static function bellOptionals(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->bellOptional()));
    }

    /** Settings → Notifications wording. */
    public function settingLabel(): string
    {
        return match ($this) {
            self::ApplicationReceived => 'Someone applies to my company',
            self::ApplicationDecided => 'My application is accepted or declined',
            self::AppLinked => 'A ProjectTrucks app is linked to my account',
            self::CompanyNews => 'News from my company',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }

    public function settingDescription(): string
    {
        return match ($this) {
            self::ApplicationReceived => 'Sent to the company owner and managers.',
            self::ApplicationDecided => 'Sent when an owner or manager decides.',
            self::AppLinked => 'A security notice, so you know if it wasn’t you.',
            self::CompanyNews => 'When an owner or manager posts news to your company.',
            default => '',
        };
    }
}
