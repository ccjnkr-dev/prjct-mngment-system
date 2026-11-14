<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveClientRequest;
use App\Models\Client;
use App\Services\ClientDirectory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $filters = $request->validate(['q' => 'nullable|string|max:160', 'status' => 'nullable|in:active,inactive']);
        $clients = Client::withCount('projects')->when($filters['q'] ?? null, function ($query, $search): void {
            $query->where(function ($query) use ($search): void {
                foreach (['name', 'business_type', 'industry', 'contact_name', 'email', 'phone'] as $field) {
                    $query->orWhereLike($field, '%'.$search.'%');
                }
            });
        })->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->orderBy('name')->paginate(20)->withQueryString();

        return view('clients.index', compact('clients'));
    }

    public function store(SaveClientRequest $request): RedirectResponse
    {
        Client::create($request->validated());

        return back()->with('success', 'Client added.');
    }

    public function edit(Request $request, Client $client): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $client->load(['projects' => fn ($query) => $query->latest()]);

        return view('clients.edit', compact('client'));
    }

    public function update(SaveClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        return redirect('/clients/'.$client->id.'/edit')->with('success', 'Client information updated.');
    }

    public function destroy(Request $request, Client $client, ClientDirectory $directory): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $directory->delete($client);

        if ($request->expectsJson()) {
            $request->session()->flash('success', 'Client deleted.');

            return response()->json(['redirect' => '/clients']);
        }

        return redirect('/clients')->with('success', 'Client deleted.');
    }
}
