<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Admin\Audit;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /** Straight to the edit page, where the Preview button is. */
    protected function getRedirectUrl(): string
    {
        return PostResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PostResource::withPublishTime($data);
    }

    protected function afterCreate(): void
    {
        /** @var Post $post */
        $post = $this->getRecord();
        Audit::record('post.created', $post, null, PostResource::auditFields($post));
    }
}
