<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentInvitation;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        $totalDocuments = Document::query()->count();
        $completedDocuments = Document::query()->whereNotNull('completed_at')->count();
        $totalComments = Comment::query()->count();
        $activeReviews = Document::query()
            ->whereIn('status', ['pending', 'in-review', 'reviewed'])
            ->whereNull('archived_at')
            ->count();
        $expiredReviews = Document::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNull('completed_at')
            ->whereNull('archived_at')
            ->count();
        $pendingInvitations = DocumentInvitation::query()->whereIn('status', ['pending', 'viewed'])->count();
        $completedInvitations = DocumentInvitation::query()->where('status', 'completed')->count();
        $pendingSignatures = DocumentInvitation::query()->where('invitation_type', 'sign')->whereIn('status', ['pending', 'viewed'])->count();
        $completedSignatures = Signature::query()->count();

        return response()->json([
            'data' => [
                'totalUsers' => User::query()->count(),
                'totalDocuments' => $totalDocuments,
                'totalComments' => $totalComments,
                'activeReviews' => $activeReviews,
                'expiredReviews' => $expiredReviews,
                'completionRate' => $totalDocuments > 0 ? round(($completedDocuments / $totalDocuments) * 100, 2) : 0,
                'averageCommentsPerDoc' => $totalDocuments > 0 ? round($totalComments / $totalDocuments, 2) : 0,
                'recentActivity' => AuditLog::query()
                    ->orderByDesc('timestamp')
                    ->limit(10)
                    ->get()
                    ->map(fn (AuditLog $log) => [
                        'id' => (string) $log->id,
                        'timestamp' => optional($log->timestamp)->toISOString(),
                        'action' => $log->action,
                        'eventType' => $log->event_type,
                        'performedBy' => $log->performed_by,
                        'documentTitle' => $log->document_title,
                        'details' => $log->details,
                    ])
                    ->values(),
                'totalRegularUsers' => User::query()->where('role', 'user')->count(),
                'totalAdmins' => User::query()->where('role', 'admin')->count(),
                'pendingInvitations' => $pendingInvitations,
                'completedInvitations' => $completedInvitations,
                'pendingSignatures' => $pendingSignatures,
                'completedSignatures' => $completedSignatures,
            ],
        ]);
    }
}

