<?php

namespace App\Filament;

use App\Admin\Moderation;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * A staff moderation button (#4651): confirmation modal with a required reason, then one of
 * {@see Moderation}'s methods. When the record has changed since the page loaded
 * (another staff member got there first), the method refuses and the toast says why.
 */
final class ModerationAction
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model  the record type the action runs on
     * @param  (Closure(TModel, string, User|null): void)|null  $run  gets the record, the reason and the staff member
     * @param  array<int, mixed>  $extraFields  form fields shown above the reason
     * @param  (Closure(TModel, array<string, mixed>, string, User|null): void)|null  $runWithData
     */
    public static function make(
        string $name,
        string $model,
        string $label,
        string $heading,
        string $description,
        string $done,
        ?Closure $run = null,
        string $color = 'danger',
        string $reasonLabel = 'Reason (goes in the audit log)',
        array $extraFields = [],
        ?Closure $runWithData = null,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalSubmitActionLabel($label)
            ->schema([
                ...$extraFields,
                Textarea::make('reason')
                    ->label($reasonLabel)
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (Model $record, array $data) use ($model, $run, $runWithData, $done): void {
                if (! $record instanceof $model) {
                    throw new RuntimeException("Expected a {$model} record.");
                }

                $staff = Auth::user() instanceof User ? Auth::user() : null;
                $reason = trim((string) $data['reason']);

                try {
                    if ($runWithData !== null) {
                        $runWithData($record, $data, $reason, $staff);
                    } elseif ($run !== null) {
                        $run($record, $reason, $staff);
                    }
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($done)->success()->send();
            });
    }
}
