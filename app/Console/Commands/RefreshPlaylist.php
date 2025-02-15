<?php

namespace App\Console\Commands;

use App\Enums\PlaylistStatus;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;

class RefreshPlaylist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-playlist {playlist?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh playlist in batch (or specific playlist when ID provided)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $playlistId = $this->argument('playlist');
        if ($playlistId) {
            $this->info("Refreshing playlist with ID: {$playlistId}");
            $playlist = Playlist::findOrFail($playlistId);
            dispatch(new ProcessM3uImport($playlist));
            $this->info('Dispatched playlist for refresh');
        } else {
            $this->info('Refreshing all playlists');
            $eightHoursAgo = now()->subHours(8); // lowest interval
            $playlists = Playlist::query()->where(
                'status',
                '!=',
                PlaylistStatus::Processing,
            )->whereDate('synced', '<=', $eightHoursAgo);
            $count = $playlists->count();
            if ($count === 0) {
                $this->info('No playlists ready refresh');
                return;
            }
            $playlists->get()->each(function (Playlist $playlist) {
                // Check the sync interval to see if we need to refresh yet
                $interval = CarbonInterval::make($playlist->interval ?? '24 hours');
                $nextSync = $playlist->synced->add($interval);
                if (!$nextSync->isFuture()) {
                    dispatch(new ProcessM3uImport($playlist));
                }
            });
            $this->info('Dispatched ' . $count . ' playlists for refresh');
        }
        return;
    }
}
