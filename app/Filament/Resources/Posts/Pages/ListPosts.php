<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Models\PostImport;
use App\Support\PostDrafts;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    /** New repo post files become drafts here too (#5153): the scheduler may not be running. */
    public function mount(): void
    {
        parent::mount();

        $imported = array_filter(PostDrafts::import(), fn (array $r) => $r['outcome'] === PostImport::IMPORTED);
        if ($imported !== []) {
            Notification::make()
                ->title(count($imported) === 1 ? '1 new draft from the repo' : count($imported).' new drafts from the repo')
                ->body('Open a draft, read it, and set Status to Published to put it on the blog.')
                ->success()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New post'),
        ];
    }
}
