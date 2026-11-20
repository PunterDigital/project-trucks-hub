<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Admin\Audit;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Post $record
 */
class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    protected function getHeaderActions(): array
    {
        return [
            // The saved version, rendered exactly as readers will see it.
            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn (): string => route('blog.preview', $this->record))
                ->openUrlInNewTab(),
            Action::make('view')
                ->label('View on the blog')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => $this->record->isPublic())
                ->url(fn (): string => route('blog.show', $this->record->slug))
                ->openUrlInNewTab(),
            DeleteAction::make()
                ->after(fn (Post $record) => Audit::record('post.deleted', $record, PostResource::auditFields($record))),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PostResource::withPublishTime($data);
    }

    protected function beforeSave(): void
    {
        $this->before = PostResource::auditFields($this->record);
    }

    protected function afterSave(): void
    {
        $this->record->refresh();
        $after = PostResource::auditFields($this->record);
        $wasPublic = $this->before['status'] === 'published';

        $action = match (true) {
            ! $wasPublic && $after['status'] === 'published' => 'post.published',
            $wasPublic && $after['status'] === 'draft' => 'post.unpublished',
            default => 'post.updated',
        };

        Audit::record($action, $this->record, $this->before, $after);
    }
}
