<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DocumentStorageSettingController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(AuthenticateApiToken::class)->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    Route::get('/settings/document-storage-mode', [DocumentStorageSettingController::class, 'show']);
    Route::put('/settings/document-storage-mode', [DocumentStorageSettingController::class, 'update']);

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::get('/documents/{document}/file', [DocumentController::class, 'downloadFile'])->name('documents.file');
    Route::post('/documents/{document}/acknowledge', [DocumentController::class, 'acknowledge']);
    Route::post('/documents/{document}/invite-signature', [DocumentController::class, 'inviteSignature']);
    Route::post('/documents/{document}/reassign', [DocumentController::class, 'reassign']);
    Route::post('/documents/{document}/days', [DocumentController::class, 'updateDays']);
    Route::post('/documents/{document}/status', [DocumentController::class, 'updateStatus']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

    Route::get('/documents/{document}/comments', [CommentController::class, 'index']);
    Route::post('/documents/{document}/comments', [CommentController::class, 'store']);
    Route::delete('/documents/{document}/comments/{comment}', [CommentController::class, 'destroy']);

    Route::get('/documents/{document}/signatures', [SignatureController::class, 'index']);
    Route::post('/documents/{document}/signatures', [SignatureController::class, 'store']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    Route::delete('/audit-logs', [AuditLogController::class, 'destroy']);
});
