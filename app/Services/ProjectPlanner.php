<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class ProjectPlanner
{
    public function create(array $data): Project
    {
        return DB::transaction(function () use ($data) {
            $members = $data['members'] ?? [];
            unset($data['members']);
            $project = Project::create($data);
            $project->members()->sync($members);

            return $project;
        });
    }

    public function update(Project $project, array $data): void
    {
        DB::transaction(function () use ($project, $data) {
            $members = $data['members'] ?? [];
            unset($data['members']);
            $project->update($data);
            $project->members()->sync($members);
        });
    }

    public function saveTask(Project $project, array $data, ?Task $task): Task
    {
        if (! empty($data['assignee_id'])) {
            abort_unless($project->members()->where('users.id', $data['assignee_id'])->where('active', true)->exists(), 422, 'Assignee must be an active project member.');
        }
        if ($data['milestone']) {
            $date = $data['start_date'] ?? $data['due_date'] ?? null;
            $data['start_date'] = $date;
            $data['due_date'] = $date;
        }
        if ($task) {
            $task->update($data);

            return $task;
        }

        return $project->tasks()->create($data);
    }
}
