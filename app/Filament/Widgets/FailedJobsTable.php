<?php

namespace App\Filament\Widgets;

use App\Admin\Audit;
use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

/** Failed jobs with Retry / Forget, both in the audit log (#4666). */
class FailedJobsTable extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Failed jobs')
            ->query(fn (): Builder => FailedJob::query()->latest('failed_at')->latest('id'))
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No failed jobs')
            ->columns([
                TextColumn::make('name')->label('Job')->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->tooltip(fn (FailedJob $record): string => $record->name),
                TextColumn::make('queue')->label('Queue'),
                TextColumn::make('exception_summary')->label('Exception')->wrap()->color('danger'),
                TextColumn::make('failed_at')->label('Failed')->since()->dateTimeTooltip(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalHeading('Retry this job?')
                    ->modalDescription('It goes back on its queue and runs again.')
                    ->action(function (FailedJob $record): void {
                        Audit::record('queue.job_retried', $record, null, ['uuid' => $record->uuid, 'queue' => $record->queue]);
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);
                        Notification::make()->title('Job back on the queue')->success()->send();
                    }),
                Action::make('forget')
                    ->label('Forget')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Forget this failed job?')
                    ->modalDescription('It is deleted and won\'t run again.')
                    ->action(function (FailedJob $record): void {
                        Audit::record('queue.job_forgotten', $record, ['uuid' => $record->uuid, 'exception' => $record->exception_summary]);
                        Artisan::call('queue:forget', ['id' => $record->uuid]);
                        Notification::make()->title('Failed job forgotten')->success()->send();
                    }),
            ]);
    }
}
