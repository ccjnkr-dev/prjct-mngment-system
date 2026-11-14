<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:160', 'business_type' => 'nullable|string|max:160', 'industry' => 'nullable|string|max:160', 'contact_name' => 'nullable|string|max:160', 'contact_position' => 'nullable|string|max:160', 'email' => 'nullable|email|max:200', 'phone' => 'nullable|string|max:60', 'website' => 'nullable|url:http,https|max:500', 'address' => 'nullable|string|max:2000', 'status' => 'sometimes|required|in:active,inactive', 'notes' => 'nullable|string|max:10000',
        ];
    }
}
