<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientDirectory
{
    public function delete(Client $client): void
    {
        DB::transaction(function () use ($client): void {
            $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            if ($locked->projects()->exists()) {
                throw ValidationException::withMessages(['client_delete' => 'This client has linked projects and cannot be deleted. Mark it inactive instead.']);
            }
            $locked->delete();
        });
    }
}
