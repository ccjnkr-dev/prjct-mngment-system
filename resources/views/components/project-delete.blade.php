@props(['project'])
@can('manage', $project)
<button type="button" class="button secondary danger" data-open="delete-project-{{ $project->id }}">Delete project</button>
<dialog id="delete-project-{{ $project->id }}" aria-labelledby="delete-project-title-{{ $project->id }}">
<form method="post" action="{{ route('projects.destroy', $project) }}" class="stack">@csrf @method('DELETE')
<h2 id="delete-project-title-{{ $project->id }}">Delete {{ $project->name }}?</h2>
<p>This permanently removes the project, tasks, checklists, notes, team assignments, asset records, and activity history. Queued uploads will be canceled.</p>
<p><strong>The Google Drive folder and its uploaded files will be preserved.</strong> Uploads already in progress must finish first.</p>
<div class="row wrap"><button type="button" class="button secondary" data-close>Cancel</button><button class="button destructive" data-loading-label="Deleting project…">Delete permanently</button></div>
</form></dialog>
@endcan
