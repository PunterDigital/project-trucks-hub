<?php

namespace App\Enums;

/** Why a driver reported a profile, company or job (#4651). */
enum ReportReason: string
{
    case Offensive = 'offensive';
    case Spam = 'spam';
    case Cheating = 'cheating';
    case Impersonation = 'impersonation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Offensive => 'Offensive name or text',
            self::Spam => 'Spam or advertising',
            self::Cheating => 'Cheated or fake jobs',
            self::Impersonation => 'Pretending to be someone else',
            self::Other => 'Something else',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
