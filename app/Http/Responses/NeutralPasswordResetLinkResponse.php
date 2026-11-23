<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Card 5532: "Forgot password" answers the same whether or not the address
 * has an account (Fortify's failed response said "We can't find a user with
 * that email address."), like the neutral sign-up (card 4034). A per-address
 * throttle ("Please wait before retrying") only happens after a real send,
 * so it gets the same answer too.
 */
class NeutralPasswordResetLinkResponse extends SuccessfulPasswordResetLinkRequestResponse implements FailedPasswordResetLinkRequestResponse
{
    /**
     * Fortify passes the real (failed) status; it is ignored on purpose.
     */
    public function __construct()
    {
        parent::__construct(Password::RESET_LINK_SENT);
    }
}
