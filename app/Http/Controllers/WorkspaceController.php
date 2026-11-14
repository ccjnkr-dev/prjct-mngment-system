<?php

namespace App\Http\Controllers;

use App\Http\Resources\TimelineResource;
use App\Jobs\UploadDriveAsset;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\DriveConnection;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectDeletion;
use App\Services\ProjectPlanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function __construct(private readonly ProjectPlanner $planner) {}

    public function index(Request $request): View
    {
        $projects = Project::visibleTo($request->user())->with(['client', 'tasks'])->latest()->get();
        $openProjects = $projects->whereIn('status', ['active', 'on_hold']);
        $tasks = $openProjects->flatMap->tasks;
        $stats = [
            'total' => $projects->count(),
            'active' => $projects->where('status', 'active')->count(),
            'completed' => $projects->where('status', 'completed')->count(),
            'overdue' => $tasks->filter(fn (Task $task) => $task->status !== 'done' && $task->due_date && $task->due_date < today()->toDateString())->count(),
        ];
        $deadlines = $openProjects->flatMap(fn (Project $project) => $project->tasks
            ->filter(fn (Task $task) => $task->status !== 'done' && $task->due_date)
            ->map(fn (Task $task) => ['task' => $task, 'project' => $project]))
            ->sortBy(fn (array $entry) => $entry['task']->due_date)->take(8);
        $activities = Activity::whereIn('project_id', $projects->pluck('id'))->with('user')->latest()->limit(10)->get();

        return view('dashboard', compact('projects', 'stats', 'deadlines', 'activities'));
    }

    public function projects(Request $r): mixed
    {
        $projects = Project::visibleTo($r->user())->with(['client', 'members', 'tasks.assignee'])->when($r->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$r->string('q').'%'))->when($r->filled('status'), fn ($q) => $q->where('status', $r->string('status')))->when($r->filled('client'), fn ($q) => $q->where('client_id', $r->integer('client')))->when($r->filled('assignee'), fn ($q) => $q->whereHas('tasks', fn ($t) => $t->where('assignee_id', $r->integer('assignee'))))->latest()->get();

        return view('projects', ['projects' => $projects, 'clients' => Client::when($r->user()->role !== 'admin', fn ($q) => $q->whereHas('projects', fn ($p) => $p->visibleTo($r->user())))->orderBy('name')->get(), 'users' => User::where('active', true)->when($r->user()->role !== 'admin', fn ($q) => $q->whereHas('projects', fn ($p) => $p->visibleTo($r->user())))->get()]);
    }

    public function show(Project $project): mixed
    {
        Gate::authorize('view', $project);
        $project->load(['client', 'members', 'tasks.assignee', 'tasks.checklistItems', 'notes.author', 'assets.uploader', 'activities.user']);

        return view('project', compact('project'));
    }

    public function create(Request $r): mixed
    {
        $this->admin($r);
        $d = $r->validate(['name' => 'required|string|max:160', 'client_id' => 'required|exists:clients,id', 'description' => 'nullable|string|max:10000', 'start_date' => 'nullable|date', 'due_date' => 'nullable|date'.($r->filled('start_date') ? '|after_or_equal:start_date' : ''), 'members' => 'array', 'members.*' => 'exists:users,id']);
        $p = $this->planner->create($d);

        return redirect('/projects/'.$p->id);
    }

    public function update(Request $r, Project $project): mixed
    {
        Gate::authorize('manage', $project);
        $d = $r->validate(['name' => 'required|string|max:160', 'description' => 'nullable|string|max:10000', 'status' => 'required|in:active,on_hold,completed,archived', 'start_date' => 'nullable|date', 'due_date' => 'nullable|date'.($r->filled('start_date') ? '|after_or_equal:start_date' : ''), 'members' => 'array', 'members.*' => 'exists:users,id']);
        $this->planner->update($project, $d);
        $this->activity($project, 'Updated project settings');

        return back()->with('success', 'Project saved.');
    }

    public function destroy(Project $project, ProjectDeletion $deletion): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $deletion->delete($project);

        return redirect('/projects')->with('success', 'Project deleted. Google Drive files have been preserved.');
    }

    public function task(Request $r, Project $project, ?Task $task = null): mixed
    {
        Gate::authorize('update', $project);
        if ($task) {
            abort_unless($task->project_id === $project->id, 404);
        }
        $d = $r->validate(['title' => 'required|string|max:200', 'status' => 'required|in:todo,in_progress,in_review,blocked,done', 'priority' => 'required|in:low,medium,high', 'assignee_id' => 'nullable|integer', 'start_date' => 'nullable|date', 'due_date' => 'nullable|date'.($r->filled('start_date') ? '|after_or_equal:start_date' : ''), 'milestone' => 'boolean']);
        $d['milestone'] = $r->boolean('milestone');
        $this->planner->saveTask($project, $d, $task);
        $this->activity($project, ($task ? 'Updated task: ' : 'Created task: ').$d['title']);

        return back()->with('success', 'Task saved.');
    }

    public function checklist(Request $r, Project $project, Task $task): mixed
    {
        Gate::authorize('update', $project);
        abort_unless($task->project_id === $project->id, 404);
        $task->checklistItems()->create($r->validate(['title' => 'required|string|max:200']));
        $this->activity($project, 'Added checklist item');

        return back();
    }

    public function toggle(Request $r, Project $project, ChecklistItem $item): mixed
    {
        Gate::authorize('update', $project);
        abort_unless($item->task->project_id === $project->id, 404);
        $item->update(['done' => ! $item->done]);
        $this->activity($project, 'Updated checklist: '.$item->title);

        return back();
    }

    public function note(Request $r, Project $project, ?Note $note = null): mixed
    {
        Gate::authorize('update', $project);
        if ($note) {
            abort_unless($note->project_id === $project->id, 404);
        }
        $d = $r->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:50000', 'pinned' => 'boolean']);
        $d['pinned'] = $r->boolean('pinned');
        if ($note) {
            $note->update($d);
        } else {
            $project->notes()->create([...$d, 'user_id' => $r->user()->id]);
        }
        $this->activity($project, 'Saved note: '.$d['title']);

        return back()->with('success', 'Note saved.');
    }

    public function asset(Request $r, Project $project): mixed
    {
        Gate::authorize('update', $project);
        $r->validate(['file' => 'required|file|max:102400']);
        abort_unless(DriveConnection::exists(), 422, 'An administrator must connect Google Drive first.');
        $f = $r->file('file');
        $a = $project->assets()->create(['user_id' => $r->user()->id, 'name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'path' => $f->store('uploads', 'local')]);
        UploadDriveAsset::dispatch($a->id);
        $this->activity($project, 'Uploaded asset: '.$a->name);

        return back()->with('success', 'Upload queued for Google Drive.');
    }

    public function link(Request $r, Project $project): mixed
    {
        Gate::authorize('update', $project);
        $d = $r->validate(['name' => 'required|string|max:200', 'url' => ['required', 'url:https', 'regex:~^https://drive\.google\.com/~']]);
        $project->assets()->create([...$d, 'user_id' => $r->user()->id, 'status' => 'ready']);
        $this->activity($project, 'Linked asset: '.$d['name']);

        return back();
    }

    public function retry(Request $r, Project $project, Asset $asset): mixed
    {
        Gate::authorize('update', $project);
        abort_unless($asset->project_id === $project->id && $asset->status === 'failed' && $asset->path && \Storage::disk('local')->exists($asset->path), 422, 'Upload the file again.');
        $asset->update(['status' => 'queued', 'error' => null]);
        UploadDriveAsset::dispatch($asset->id);

        return back();
    }

    public function timeline(Project $project): mixed
    {
        Gate::authorize('view', $project);

        return TimelineResource::collection($project->tasks()->with('assignee')->get());
    }

    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    private function activity(Project $p, string $description): void
    {
        $p->activities()->create(['user_id' => auth()->id(), 'description' => $description]);
    }
}
