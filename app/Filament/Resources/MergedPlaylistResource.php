<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MergedPlaylistResource\Pages;
use App\Filament\Resources\MergedPlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistEpgUrl;
use App\Forms\Components\PlaylistM3uUrl;
use App\Models\MergedPlaylist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MergedPlaylistResource extends Resource
{
    protected static ?string $model = MergedPlaylist::class;

    protected static ?string $navigationIcon = 'heroicon-o-forward';

    protected static ?string $navigationGroup = 'Custom';

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->sortable(),
                Tables\Columns\TextColumn::make('enabled_channels_count')
                    ->label('Enabled Channels')
                    ->counts('enabled_channels')
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
                    Tables\Actions\DeleteAction::make(),
                ])
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PlaylistsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMergedPlaylists::route('/'),
            // 'create' => Pages\CreateMergedPlaylist::route('/create'),
            'edit' => Pages\EditMergedPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        $schema = [
            Forms\Components\TextInput::make('name')
                ->required()
                ->columnSpan(2)
                ->helperText('Enter the name of the playlist. Internal use only.'),
            PlaylistM3uUrl::make('m3u_url')
                ->hiddenOn(['create']) // hide this field on the create form
                ->columnSpan(2)
                ->dehydrated(false) // don't save the value in the database
                ->helperText('Your generated m3u playlist, based on the playlist configurtation. Only enabled channels will be included.'),
            PlaylistEpgUrl::make('epg_url')
                ->hiddenOn(['create']) // hide this field on the create form
                ->columnSpan(2)
                ->dehydrated(false) // don't save the value in the database
                ->helperText('Your generated EPG, based on the playlist configurtation. Only enabled channels will be included.'),
        ];
        return [
            Forms\Components\Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema($schema)
                ->columns(2),
            Forms\Components\Section::make()
                ->hiddenOn(['create']) // hide this field on the create form
                ->schema($schema)
                ->columns(2),

        ];
    }
}
