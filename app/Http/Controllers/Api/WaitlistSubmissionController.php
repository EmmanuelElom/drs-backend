<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WaitlistSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WaitlistSubmissionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'source_path' => ['nullable', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim($data['email']));
        $sourcePath = isset($data['source_path']) ? trim($data['source_path']) : null;

        $submission = WaitlistSubmission::query()->firstOrNew([
            'email' => $email,
        ]);

        $wasRecentlyCreated = ! $submission->exists;

        $submission->forceFill([
            'email' => $email,
            'source_path' => $sourcePath ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ])->save();

        return response()->json([
            'message' => $wasRecentlyCreated
                ? 'You are on the wait list.'
                : 'You are already on the wait list.',
            'data' => $this->serializeSubmission($submission),
        ], $wasRecentlyCreated ? 201 : 200);
    }

    private function serializeSubmission(WaitlistSubmission $submission): array
    {
        return [
            'id' => (string) $submission->id,
            'email' => $submission->email,
            'sourcePath' => $submission->source_path,
            'ipAddress' => $submission->ip_address,
            'userAgent' => $submission->user_agent,
            'createdAt' => optional($submission->created_at)->toISOString(),
            'updatedAt' => optional($submission->updated_at)->toISOString(),
        ];
    }
}
