<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FormController;
use App\Http\Controllers\SubmissionController;
use App\Livewire\FormBuilder;
use App\Livewire\FormFill;
use App\Livewire\SubmissionsList;

Route::redirect('/', '/forms');

Route::get('/forms', [FormController::class, 'index'])->name('forms.index');
Route::post('/forms', [FormController::class, 'store'])->name('forms.store');
Route::livewire('/forms/{form}/builder', FormBuilder::class)->name('forms.builder');

Route::post('/forms/ai-generate', [FormController::class, 'aiGenerate'])->name('forms.ai-generate');
Route::get('/forms/ai-generate/{trackingId}/status', [FormController::class, 'aiGenerateStatus'])->name('forms.ai-generate.status');

Route::livewire('/forms/{form}/submissions', SubmissionsList::class)->name('submissions.index');
Route::get('/forms/{form}/submissions/export', [SubmissionController::class, 'exportCsv'])->name('submissions.export');

Route::livewire('/f/{publicKey}', FormFill::class)->name('forms.public');

Route::get('/forms/{form}/submissions/{submission}/file/{fieldKey}', [SubmissionController::class, 'downloadFile'])->name('submissions.download-file');
