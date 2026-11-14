<?php

namespace App\Filament\Resources\AuditLog;

use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Models\AdminAuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin audit log (#4664): every staff action, read-only. Nothing in the app edits or
 * deletes a row.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static ?string $slug = 'audit-log';

    protected static ?string $modelLabel = 'audit log entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static ?string $navigationLabel = 'Audit log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('staff_name')
                    ->label('Staff')
                    ->searchable(),
                TextColumn::make('action')
                    ->badge()
                    ->searchable(),
                TextColumn::make('target_label')
                    ->label('Target')
                    ->description(fn (AdminAuditLog $record): ?string => $record->target_type ? $record->target_type.' #'.$record->target_id : null)
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('before')
                    ->state(fn (AdminAuditLog $record): ?string => self::json($record->before))
                    ->fontFamily('mono')
                    ->placeholder('—'),
                TextColumn::make('after')
                    ->state(fn (AdminAuditLog $record): ?string => self::json($record->after))
                    ->fontFamily('mono')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
            ]);
    }

    /** @param  array<string, mixed>|null  $data */
    private static function json(?array $data): ?string
    {
        return $data === null ? null : (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLog::route('/'),
        ];
    }
}
