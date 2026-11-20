<?php

namespace App\Http\Requests;

class ServerCompleteRequest extends CompleteQuestRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['quest_id' => ['required', 'integer']];
    }
}
