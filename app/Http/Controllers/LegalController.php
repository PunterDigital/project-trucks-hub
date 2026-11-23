<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** The privacy policy and terms of use (#4641). Plain pages, no data beyond the contact. */
class LegalController extends Controller
{
    /** Bump when the wording of either page changes. */
    public const UPDATED = '24 November 2026';

    public function privacy(): Response
    {
        return Inertia::render('legal/Privacy', [
            'contactEmail' => self::contactEmail(),
            'updated' => self::UPDATED,
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('legal/Terms', [
            'contactEmail' => self::contactEmail(),
            'updated' => self::UPDATED,
        ]);
    }

    public static function contactEmail(): ?string
    {
        $email = config('services.hub.contact_email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
