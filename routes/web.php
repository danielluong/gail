<?php

use App\Http\Controllers\AiExportController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChatController::class, 'index'])->name('home');
Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
Route::post('/', [ChatController::class, 'stream'])->name('chat.stream');
Route::get('/models', [ChatController::class, 'models'])->name('chat.models');
Route::post('/upload', [ChatController::class, 'upload'])->name('chat.upload');
Route::post('/transcribe', [ChatController::class, 'transcribe'])->name('chat.transcribe');
Route::get('/uploads/{filename}', [ChatController::class, 'show'])
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('uploads.show');

Route::get('/ai-exports/{filename}', [AiExportController::class, 'show'])
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('ai-exports.show');

Route::get('/conversations/search', [ConversationController::class, 'search'])->name('conversations.search');
Route::get('/conversations/{conversation}/export', [ConversationController::class, 'export'])->name('conversations.export');
Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages'])->name('conversations.messages');
Route::post('/conversations/{conversation}/branch', [ConversationController::class, 'branch'])->name('conversations.branch');
Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');

Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

Route::get('/projects/{project}/documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('/projects/{project}/documents', [DocumentController::class, 'store'])->name('documents.store');
Route::delete('/projects/{project}/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
