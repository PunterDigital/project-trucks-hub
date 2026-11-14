<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Without a password of their own, the driver confirms with Steam or a passkey
        // instead (checked in ProfileController::destroy).
        if (! $this->user()?->hasPassword()) {
            return [];
        }

        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
