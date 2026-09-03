<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Hanya admin HQ yang dapat melihat activity log.');
        }

        $q = ActivityLog::query()->latest('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($qq) use ($search) {
                $qq->where('actor_name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('path', 'like', '%'.$search.'%')
                    ->orWhere('action', 'like', '%'.$search.'%');
            });
        }

        if ($type = trim((string) $request->query('actor_type', ''))) {
            $q->where('actor_type', $type);
        }

        if ($from = $request->query('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 30)));

        return $q->paginate($perPage);
    }
}
