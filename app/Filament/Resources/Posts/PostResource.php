<?php

namespace App\Filament\Resources\Posts;

use App\Enums\ImageKind;
use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Support\ImageStore;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Blog posts (#5150): staff write them in Markdown, preview them (the Preview button opens the
 * real post page) and publish. Every create, change and delete is in the audit log.
 */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $navigationLabel = 'Blog posts';

    protected static ?string $modelLabel = 'blog post';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'title';

    /** Paths under /blog that aren't posts. */
    public const RESERVED_SLUGS = ['feed', 'preview'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Post')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(160)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if (blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('Address')
                        ->prefix('/blog/')
                        ->helperText('Lowercase words joined by hyphens. Changing it later breaks links to the post.')
                        ->required()
                        ->maxLength(160)
                        ->regex('/^'.Post::SLUG_PATTERN.'$/')
                        ->notIn(self::RESERVED_SLUGS)
                        ->rule(fn (?Post $record) => Rule::unique('posts', 'slug')->ignore($record?->id)),
                    Textarea::make('excerpt')
                        ->label('Summary')
                        ->helperText('One or two sentences. Shown on /blog, in search results and link previews.')
                        ->required()
                        ->maxLength(300)
                        ->rows(2),
                    MarkdownEditor::make('body')
                        ->required()
                        ->fileAttachments(false)
                        ->helperText('Markdown. HTML in the text is removed when the post is shown.'),
                    FileUpload::make('cover_path')
                        ->label('Cover image')
                        ->helperText('PNG, JPEG or WebP, up to 2 MB. Cropped to 1200 × 630.')
                        ->image()
                        ->disk(ImageStore::DISK)
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->maxSize(2048)
                        // Re-encoded like every other upload: no original bytes (EXIF, GPS) are kept.
                        ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => ImageStore::store($file, ImageKind::Cover)),
                ]),
            Section::make('Publishing')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->options(collect(PostStatus::cases())->mapWithKeys(fn (PostStatus $s) => [$s->value => $s->label()])->all())
                        ->default(PostStatus::Draft->value)
                        ->native()
                        ->selectablePlaceholder(false)
                        ->required(),
                    DateTimePicker::make('published_at')
                        ->label('Publish at')
                        ->seconds(false)
                        ->helperText('Leave empty to publish now. A later time keeps the post hidden until then.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Post $record): string => self::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('title')
                    ->description(fn (Post $record): string => '/blog/'.$record->slug)
                    ->searchable(['title', 'slug'])
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Post $record): string => self::stateLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Scheduled' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('published_at')
                    ->label('Publish at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last edited')
                    ->since()
                    ->sortable(),
            ]);
    }

    /** Draft, Scheduled (published with a future time) or Published. */
    public static function stateLabel(Post $post): string
    {
        if ($post->status === PostStatus::Draft) {
            return 'Draft';
        }

        return $post->isPublic() ? 'Published' : 'Scheduled';
    }

    /**
     * Fills in "now" for a post published without a time.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withPublishTime(array $data): array
    {
        $status = $data['status'] ?? null;
        $status = $status instanceof PostStatus ? $status->value : $status;

        if ($status === PostStatus::Published->value && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        return $data;
    }

    /**
     * What the audit log keeps of a post (no body: it can be long).
     *
     * @return array<string, mixed>
     */
    public static function auditFields(Post $post): array
    {
        return [
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status->value,
            'published_at' => $post->published_at?->toIso8601String(),
            'cover' => $post->cover_path !== null,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
