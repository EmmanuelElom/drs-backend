<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DevNotificationEventController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentFieldController;
use App\Http\Controllers\Api\DocumentInvitationController;
use App\Http\Controllers\Api\DocumentStorageSettingController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\WaitlistSubmissionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:api');
    Route::put('/me', [AuthController::class, 'updateProfile'])->middleware('auth:api');
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:api');
Route::put('/me', [AuthController::class, 'updateProfile'])->middleware('auth:api');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');

Route::post('/waitlist', [WaitlistSubmissionController::class, 'store'])
    ->middleware('throttle:waitlist-submissions');

Route::middleware('auth:api')->group(function () {
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    Route::get('/settings/document-storage-mode', [DocumentStorageSettingController::class, 'show']);
    Route::put('/settings/document-storage-mode', [DocumentStorageSettingController::class, 'update']);

    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::put('/documents/{document}', [DocumentController::class, 'update']);
    Route::post('/documents/{document}/archive', [DocumentController::class, 'archive']);
    Route::post('/documents/{document}/upload', [DocumentController::class, 'upload']);
    Route::post('/documents/{document}/assignments', [AssignmentController::class, 'store']);
    Route::get('/documents/{document}/preview', [DocumentController::class, 'preview']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
    Route::get('/documents/{document}/file', [DocumentController::class, 'downloadFile'])->name('documents.file');
    Route::post('/documents/{document}/acknowledge', [DocumentController::class, 'acknowledge']);
    Route::post('/documents/{document}/invite-signature', [DocumentController::class, 'inviteSignature']);
    Route::post('/documents/{document}/reassign', [DocumentController::class, 'reassign']);
    Route::post('/documents/{document}/days', [DocumentController::class, 'updateDays']);
    Route::post('/documents/{document}/status', [DocumentController::class, 'updateStatus']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
    Route::get('/documents/{document}/fields', [DocumentFieldController::class, 'index']);
    Route::post('/documents/{document}/fields', [DocumentFieldController::class, 'store']);
    Route::put('/fields/{field}', [DocumentFieldController::class, 'update']);
    Route::delete('/fields/{field}', [DocumentFieldController::class, 'destroy']);
    Route::post('/assignments/{assignment}/acknowledge-review', [AssignmentController::class, 'acknowledgeReview']);
    Route::post('/assignments/{assignment}/invite-signature', [AssignmentController::class, 'inviteSignature']);
    Route::post('/assignments/{assignment}/complete-signature', [AssignmentController::class, 'completeSignature']);
    Route::put('/assignments/{assignment}/review-period', [AssignmentController::class, 'updateReviewPeriod']);
    Route::post('/assignments/{assignment}/reassign', [AssignmentController::class, 'reassign']);
    Route::get('/documents/{document}/invitations', [DocumentInvitationController::class, 'index']);
    Route::post('/documents/{document}/invitations', [DocumentInvitationController::class, 'store']);
    Route::post('/invitations/{invitation}/resend', [DocumentInvitationController::class, 'resend']);
    Route::post('/invitations/{invitation}/revoke', [DocumentInvitationController::class, 'revoke']);

    Route::get('/documents/{document}/comments', [CommentController::class, 'index']);
    Route::post('/documents/{document}/comments', [CommentController::class, 'store']);
    Route::delete('/documents/{document}/comments/{comment}', [CommentController::class, 'destroy']);
    Route::put('/comments/{comment}', [CommentController::class, 'update']);
    Route::delete('/comments/{comment}', [CommentController::class, 'destroyByComment']);

    Route::get('/documents/{document}/signatures', [SignatureController::class, 'index']);
    Route::post('/documents/{document}/signatures', [SignatureController::class, 'store']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/export', [AuditLogController::class, 'export']);
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    Route::delete('/audit-logs', [AuditLogController::class, 'destroy']);
});

Route::get('/access/{token}', [DocumentInvitationController::class, 'access']);
Route::post('/access/{token}/comment', [DocumentInvitationController::class, 'comment']);
Route::post('/access/{token}/review', [DocumentInvitationController::class, 'review']);
Route::post('/access/{token}/sign', [DocumentInvitationController::class, 'sign']);
Route::post('/access/{token}/complete', [DocumentInvitationController::class, 'complete']);
Route::get('/invitations', [DocumentInvitationController::class, 'inbox'])->middleware('auth:api');

Route::prefix('dev')->middleware('auth:api')->group(function () {
    Route::get('/notification-events', [DevNotificationEventController::class, 'index']);
    Route::delete('/notification-events', [DevNotificationEventController::class, 'destroy']);
});
