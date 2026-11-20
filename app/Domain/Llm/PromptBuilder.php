<?php

namespace App\Domain\Llm;

use App\Models\PromptVersion;

/**
 * Builds chat messages from a versioned prompt. User-controlled values are
 * JSON-encoded inside an <input> block that the system prompt declares to be
 * data only, keeping instructions and untrusted input separate.
 */
class PromptBuilder
{
    /**
     * @param  array<string, mixed>  $input
     * @return list<array{role: string, content: string}>
     */
    public function build(PromptVersion $prompt, array $input): array
    {
        $payload = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        $user = strtr($prompt->template, [
            '{{count}}' => (string) (int) $input['count'],
            '{{input}}' => '<input>'.$payload.'</input>',
        ]);

        return [
            ['role' => 'system', 'content' => $prompt->system_prompt],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<string>  $errors
     * @return list<array{role: string, content: string}>
     */
    public function withCorrection(array $messages, string $badOutput, array $errors): array
    {
        $messages[] = ['role' => 'assistant', 'content' => mb_substr($badOutput, 0, 4000)];
        $messages[] = ['role' => 'user', 'content' => "Your previous answer did not match the required JSON schema:\n- "
            .implode("\n- ", array_slice($errors, 0, 10))
            ."\nReturn ONLY the corrected JSON object."];

        return $messages;
    }
}
