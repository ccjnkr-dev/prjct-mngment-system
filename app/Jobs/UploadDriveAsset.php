<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Project;
use App\Services\GoogleDrive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadDriveAsset implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $assetId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('asset-'.$this->assetId))->expireAfter(1000)];
    }

    public function handle(GoogleDrive $drive): void
    {
        $queued = Asset::find($this->assetId);
        if (! $queued) {
            return;
        }
        $asset = DB::transaction(function () use ($queued): ?Asset {
            $project = Project::whereKey($queued->project_id)->lockForUpdate()->first();
            if (! $project) {
                return null;
            }
            $asset = Asset::find($this->assetId);
            if (! $asset || $asset->status === 'ready') {
                return null;
            }
            $asset->update(['status' => 'uploading', 'error' => null]);

            return $asset;
        });
        if (! $asset) {
            return;
        }
        $drive->upload($asset);
    }

    public function failed(?Throwable $e): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset || $asset->status === 'ready') {
            return;
        }
        if ($asset->path) {
            Storage::disk('local')->delete($asset->path);
        }
        $asset->update(['status' => 'failed', 'path' => null, 'error' => 'Drive upload failed. Check the Google connection and upload the file again.']);
        logger()->warning('Drive upload failed', ['asset_id' => $this->assetId, 'exception_type' => $e?->getMessage() ? get_class($e) : null]);
    }
}
