<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
