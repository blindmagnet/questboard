<?php

namespace App\Http\Requests;

use App\Domain\Webhooks\UrlGuard;
use App\Models\WebhookEndpoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebhookRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'url' => [$required, 'string', 'url', 'max:2048', function (string $attribute, mixed $value, Closure $fail) {
                if (! app(UrlGuard::class)->isAllowed((string) $value)) {
                    $fail('The webhook URL must use HTTPS and point to a public host.');
                }
            }],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['distinct', Rule::in(WebhookEndpoint::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
