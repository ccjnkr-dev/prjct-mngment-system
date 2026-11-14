<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'start' => $this->start_date, 'end' => $this->due_date, 'status' => $this->status, 'milestone' => $this->milestone, 'priority' => $this->priority, 'assignee_id' => $this->assignee_id, 'assignee' => $this->assignee?->name];
    }
}
