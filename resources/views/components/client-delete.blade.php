@props(['client', 'projectCount' => 0])
<button type="button" class="text-button danger" data-open="delete-client-{{ $client->id }}" aria-label="Delete {{ $client->name }}">Delete</button>
<dialog class="client-delete-dialog" id="delete-client-{{ $client->id }}" aria-labelledby="delete-title-{{ $client->id }}">
@if($projectCount > 0)
<div class="stack"><h2 id="delete-title-{{ $client->id }}">This client has linked projects</h2><p>{{ $client->name }} has {{ $projectCount }} {{ $projectCount == 1 ? 'project' : 'projects' }}. It cannot be deleted. Mark the client inactive to keep its project history.</p><button type="button" class="button secondary" data-close>Close</button></div>
@else
<form method="post" action="{{ route('clients.destroy', $client) }}" class="stack">@csrf @method('DELETE')
<h2 id="delete-title-{{ $client->id }}">Delete {{ $client->name }}?</h2><p>This permanently removes the client’s company details, contact information, and notes. This action cannot be undone.</p>
<div class="row"><button type="button" class="button secondary" data-close>Cancel</button><button class="button destructive" data-loading-label="Deleting client…">Delete client</button></div>
</form>
@endif
</dialog>
