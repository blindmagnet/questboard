<?php

namespace App\Http\Controllers\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /** @return CursorPaginator<int, AuditLog> */
    public function index(Request $request): CursorPaginator
    {
        $request->validate([
            'game_id' => ['sometimes', 'integer'],
            'action' => ['sometimes', 'string', 'max:64'],
            'actor_id' => ['sometimes', 'integer'],
        ]);

        return AuditLog::query()
            ->when($request->input('game_id'), fn ($q, $v) => $q->where('game_id', $v))
            ->when($request->input('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->input('actor_id'), fn ($q, $v) => $q->where('actor_id', $v))
            ->orderByDesc('id')
            ->cursorPaginate(50);
    }
}
