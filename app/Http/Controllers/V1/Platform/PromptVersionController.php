<?php

namespace App\Http\Controllers\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PromptVersion;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Versioned prompts. Activating an older version is the rollback mechanism. */
class PromptVersionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => PromptVersion::query()->orderBy('name')->orderByDesc('version')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'system_prompt' => ['required', 'string', 'max:10000'],
            'template' => ['required', 'string', 'max:10000', 'regex:/\{\{input\}\}/'],
        ]);
        $name = $data['name'] ?? PromptVersion::QUEST_GENERATOR;

        $prompt = DB::transaction(function () use ($name, $data) {
            $version = (int) PromptVersion::query()->where('name', $name)->lockForUpdate()->max('version') + 1;

            return PromptVersion::query()->create([
                'name' => $name,
                'version' => $version,
                'system_prompt' => $data['system_prompt'],
                'template' => $data['template'],
                'is_active' => false,
            ]);
        });
        $this->audit->log('prompt.created', $prompt, ['name' => $name, 'version' => $prompt->version]);

        return response()->json(['data' => $prompt], 201);
    }

    public function activate(PromptVersion $promptVersion): JsonResponse
    {
        DB::transaction(function () use ($promptVersion) {
            PromptVersion::query()->where('name', $promptVersion->name)->update(['is_active' => false]);
            $promptVersion->forceFill(['is_active' => true])->save();
        });
        $this->audit->log('prompt.activated', $promptVersion, ['version' => $promptVersion->version]);

        return response()->json(['data' => $promptVersion->refresh()]);
    }
}
