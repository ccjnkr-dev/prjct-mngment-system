<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProjectDeletion
{
    public function delete(Project $project): void
    {
        $paths = DB::transaction(function () use ($project): array {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->assets()->where('status', 'uploading')->exists()) {
                throw ValidationException::withMessages(['project_delete' => 'A file upload is in progress. Wait for it to finish before deleting this project.']);
            }
            $paths = $locked->assets()->whereNotNull('path')->pluck('path')->all();
            $locked->delete();

            return $paths;
        });
        foreach ($paths as $path) {
            if (! Storage::disk('local')->delete($path)) {
                logger()->warning('Could not remove deleted project staging file.', ['path' => $path]);
            }
        }
    }
}
