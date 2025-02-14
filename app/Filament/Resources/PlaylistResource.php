<?php

namespace App\Filament\Resources;

use App\Enums\PlaylistStatus;
use App\Filament\Resources\PlaylistResource\Pages;
use App\Filament\Resources\PlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistEpgUrl;
use App\Forms\Components\PlaylistM3uUrl;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static ?string $navigationIcon = 'heroicon-o-play';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 0;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll()
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('Playlist URL')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('enabled_channels_count')
                    ->label('Enabled Channels')
                    ->counts('enabled_channels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn(PlaylistStatus $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->sortable()
                    ->poll(fn($record) => $record->status === PlaylistStatus::Processing || $record->status === PlaylistStatus::Pending ? '5s' : null)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('auto_sync')
                    ->label('Auto Sync')
                    ->icon(fn(string $state): string => match ($state) {
                        '1' => 'heroicon-o-check-circle',
                        '0' => 'heroicon-o-minus-circle',
                    })->color(fn(string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                    })->toggleable()->sortable(),
                Tables\Columns\TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_interval')
                    ->label('Interval')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn(string $state): string => gmdate('H:i:s', (int)$state))
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('process')
                        ->label('Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => PlaylistStatus::Processing,
                                'progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is processing')
                                ->body('Playlist is being processed in the background. Depending on the size of your playlist, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => $record->status === PlaylistStatus::Processing)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process playlist now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('playlist.generate', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('epg.generate', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => PlaylistStatus::Pending,
                                'processing' => false,
                                'progress' => 0,
                                'channels' => 0,
                                'synced' => null,
                                'errors' => null,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist status reset')
                                ->body('Playlist status has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        // ->disabled(fn($record): bool => ! $record->auto_sync)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => PlaylistStatus::Processing,
                                    'progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected playlists are processing')
                                ->body('The selected playlists are being processed in the background. Depending on the size of your playlist, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process the selected playlist(s) now?')
                        ->modalSubmitActionLabel('Yes, process now')
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn($record): bool => $record->status !== PlaylistStatus::Processing,
            );
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlaylists::route('/'),
            //'create' => Pages\CreatePlaylist::route('/create'),
            //'edit' => Pages\EditPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->helperText('Enter the name of the playlist. Internal use only.'),
            Forms\Components\TextInput::make('url')
                ->label('Playlist URL')
                ->url()
                ->prefixIcon('heroicon-m-globe-alt')
                ->required()
                ->helperText('Enter the URL of the playlist file. If changing URL, the playlist will be re-imported. Use with caution as this could lead to data loss if the new playlist differs from the old one.'),
            Forms\Components\Toggle::make('auto_sync')
                ->label('Automatically sync playlist')
                ->helperText('When enabled, the playlist will be automatically re-synced at the specified interval.')
                ->live()
                ->inline(false)
                ->default(true),
            Forms\Components\Select::make('sync_interval')
                ->label('Sync Every')
                ->helperText('Default is every 24hr if left empty.')
                ->options([
                    '8hr' => '8hr',
                    '12hr' => '12hr',
                    '24hr' => '24hr',
                    '2 days' => '2 days',
                    '3 days' => '3 days',
                    '1 week' => '1 week',
                    '2 weeks' => '2 weeks',
                    '1 month' => '1 month',
                ])->hidden(fn(Get $get): bool => ! $get('auto_sync')),
            Forms\Components\DateTimePicker::make('synced')
                ->columnSpan(2)
                ->suffix('UTC')
                ->native(false)
                ->label('Last Synced')
                ->hidden(fn(Get $get, string $operation): bool => ! $get('auto_sync') || $operation === 'create')
                ->helperText('Playlist will be synced at the specified interval. Timestamp is automatically updated after each sync. Set to any time in the past (or future) and the next sync will run when the defined interval has passed since the time set.'),

            Forms\Components\Toggle::make('import_prefs.preprocess')
                ->label('Preprocess playlist')
                ->columnSpan('full')
                ->live()
                ->inline(false)
                ->default(false)
                ->helperText('When enabled, the playlist will be preprocessed before importing. You can then select which groups you would like to import.'),
            Forms\Components\Select::make('import_prefs.selected_groups')
                ->label('Groups to import')
                ->columnSpan('full')
                ->searchable()
                ->multiple()
                ->helperText('You will need to manually run the sync if updating the groups to import. If the list is empty, process the list and check again once complete.')
                ->options(function (Get $get): array {
                    $options = [];
                    foreach ($get('groups') ?? [] as $option) {
                        $options[$option] = $option;
                    }
                    return $options;
                })
                ->hidden(fn(Get $get): bool => ! $get('import_prefs.preprocess') || !$get('status')),

            Forms\Components\Section::make('Links')
                ->description('These links are generated based on the current playlist configuration. Only enabled channels will be included.')
                ->schema([
                    PlaylistM3uUrl::make('m3u_url')
                        ->columnSpan(2)
                        ->dehydrated(false), // don't save the value in the database
                    PlaylistEpgUrl::make('epg_url')
                        ->columnSpan(2)
                        ->dehydrated(false) // don't save the value in the database
                ])->hiddenOn(['create']),

        ];
    }
}
