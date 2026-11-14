<?php

namespace App\Livewire;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AssetList extends Component
{
    #[Locked]
    public int $projectId;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    public function render(): View
    {
        $project = Project::findOrFail($this->projectId);
        Gate::authorize('view', $project);
        abort_unless(auth()->user()->active, 403);

        return view('livewire.asset-list', ['project' => $project, 'assets' => $project->assets()->with('uploader')->latest()->get()]);
    }
}
