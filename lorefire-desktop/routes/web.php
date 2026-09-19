<?php

use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\WindowChromeController;
use App\Http\Controllers\OracleController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignPartyImageController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CharacterImageController;
use App\Http\Controllers\CharacterRestController;
use App\Http\Controllers\CharacterSpellController;
use App\Http\Controllers\CharacterSpellSlotsController;
use App\Http\Controllers\PdfExportController;
use App\Http\Controllers\EncounterController;
use App\Http\Controllers\GameSessionController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InventorySnapshotController;
use App\Http\Controllers\NpcController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SceneImageController;
use App\Http\Controllers\SpeakerProfileController;
use App\Http\Controllers\BatchSheetController;
use App\Http\Controllers\StandaloneCharacterController;
use App\Http\Controllers\StorageFileController;
use App\Http\Controllers\ChunkedAudioController;
use App\Http\Controllers\TranscriptionController;
use App\Http\Controllers\CharacterClassFeaturesController;
use App\Http\Controllers\CharacterConditionController;
use App\Http\Controllers\CharacterHpController;
use App\Http\Controllers\LiveSessionController;
use App\Http\Controllers\LiveSheetUpdateController;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Root — redirect to onboarding if not complete, otherwise campaigns
Route::get('/', function () {
    $complete = (bool) AppSetting::get('onboarding_complete', false);
    if (! $complete) {
        return redirect('/onboarding');
    }
    return redirect('/campaigns');
})->name('dashboard');

// Onboarding
Route::prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('/',              [OnboardingController::class, 'index'])->name('index');
    Route::post('settings',      [OnboardingController::class, 'saveSettings'])->name('settings');
    Route::post('complete',      [OnboardingController::class, 'complete'])->name('complete');
    Route::post('retry-python',  [OnboardingController::class, 'retryPython'])->name('retry-python');
});

Route::prefix('window')->name('window.')->group(function () {
    Route::post('minimize', [WindowChromeController::class, 'minimize'])->name('minimize');
    Route::post('maximize', [WindowChromeController::class, 'maximize'])->name('maximize');
    Route::post('close', [WindowChromeController::class, 'close'])->name('close');
});

Route::get('python-setup-status', function () {
    try {
        return response()->json(app(\App\Services\PythonSetupService::class)->sharedPayload());
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'log' => '',
            'started_at' => null,
            'onboarding_complete' => false,
        ]);
    }
})->name('python-setup.status');

// Campaigns
Route::resource('campaigns', CampaignController::class);

// Campaign party portrait generation
Route::post('campaigns/{campaign}/generate-party-portrait', [CampaignPartyImageController::class, 'generate'])
    ->name('campaigns.generate-party-portrait');
Route::post('campaigns/{campaign}/cancel-party-portrait', [CampaignPartyImageController::class, 'cancel'])
    ->name('campaigns.cancel-party-portrait');
Route::get('campaigns/{campaign}/party-portrait-status', [CampaignPartyImageController::class, 'status'])
    ->name('campaigns.party-portrait-status');

// Standalone characters (top-level, no campaign required)
Route::resource('characters', StandaloneCharacterController::class);
Route::post('characters/{character}/rest/overnight', [CharacterRestController::class, 'overnight'])
    ->name('characters.rest.overnight');
Route::patch('characters/{character}/memorization', [CharacterSpellSlotsController::class, 'update'])
    ->name('characters.memorization.update');
Route::patch('characters/{character}/hp', [CharacterHpController::class, 'update'])
    ->name('characters.hp.update');
Route::patch('characters/{character}/class-features', [CharacterClassFeaturesController::class, 'update'])
    ->name('characters.class-features.update');

// Inventory (standalone characters — no campaign prefix needed, character ID is unique)
Route::post('characters/{character}/inventory', [InventoryItemController::class, 'store'])
    ->name('characters.inventory.store');
Route::patch('characters/{character}/inventory/{item}', [InventoryItemController::class, 'update'])
    ->name('characters.inventory.update');
Route::delete('characters/{character}/inventory/{item}', [InventoryItemController::class, 'destroy'])
    ->name('characters.inventory.destroy');
Route::patch('characters/{character}/inventory/{item}/equip', [InventoryItemController::class, 'toggleEquipped'])
    ->name('characters.inventory.equip');
Route::post('characters/{character}/conditions', [CharacterConditionController::class, 'store'])
    ->name('characters.conditions.store');
Route::delete('characters/{character}/conditions/{condition}', [CharacterConditionController::class, 'destroy'])
    ->name('characters.conditions.destroy');

// Spells (flat routes — work for both campaign and standalone characters)
Route::post('characters/{character}/spells', [CharacterSpellController::class, 'store'])
    ->name('characters.spells.store');
Route::patch('characters/{character}/spells/{spell}', [CharacterSpellController::class, 'update'])
    ->name('characters.spells.update');
Route::patch('characters/{character}/spells/{spell}/prepare', [CharacterSpellController::class, 'togglePrepared'])
    ->name('characters.spells.prepare');
Route::patch('characters/{character}/spells/{spell}/cast', [CharacterSpellController::class, 'toggleCast'])
    ->name('characters.spells.cast');
Route::delete('characters/{character}/spells/{spell}', [CharacterSpellController::class, 'destroy'])
    ->name('characters.spells.destroy');

// Inventory snapshots
Route::post('characters/{character}/inventory/snapshots', [InventorySnapshotController::class, 'store'])
    ->name('characters.inventory.snapshots.store');
Route::delete('characters/{character}/inventory/snapshots/{snapshot}', [InventorySnapshotController::class, 'destroy'])
    ->name('characters.inventory.snapshots.destroy');

// Character portrait generation (flat routes — work for both campaign and standalone characters)
Route::post('characters/{character}/generate-portrait', [CharacterImageController::class, 'generate'])
    ->name('characters.generate-portrait');
Route::post('characters/{character}/cancel-portrait', [CharacterImageController::class, 'cancelGeneration'])
    ->name('characters.cancel-portrait');
Route::get('characters/{character}/portrait-status', [CharacterImageController::class, 'status'])
    ->name('characters.portrait-status');

// Scene image generation
Route::post('scene-art-prompts/{scene}/generate-image', [SceneImageController::class, 'generate'])
    ->name('scene-art-prompts.generate-image');
Route::post('scene-art-prompts/{scene}/cancel-image', [SceneImageController::class, 'cancel'])
    ->name('scene-art-prompts.cancel-image');
Route::get('scene-art-prompts/{scene}/image-status', [SceneImageController::class, 'status'])
    ->name('scene-art-prompts.image-status');
Route::patch('scene-art-prompts/{scene}', [SceneImageController::class, 'update'])
    ->name('scene-art-prompts.update');

// Characters (nested under campaign)
Route::prefix('campaigns/{campaign}')->name('campaigns.')->group(function () {
    Route::resource('characters', CharacterController::class);
    Route::resource('npcs', NpcController::class);
    Route::resource('sessions', GameSessionController::class);
    Route::get('sessions/{session}/live', [LiveSessionController::class, 'show'])->name('sessions.live');
    Route::get('sessions/{session}/live-state', [LiveSessionController::class, 'state'])->name('sessions.live-state');
    Route::post('characters/{character}/rest/overnight', [CharacterRestController::class, 'overnightForCampaign'])
        ->name('characters.rest.overnight');
    Route::patch('characters/{character}/memorization', [CharacterSpellSlotsController::class, 'updateForCampaign'])
        ->name('characters.memorization.update');
    Route::patch('characters/{character}/class-features', [CharacterClassFeaturesController::class, 'updateForCampaign'])
        ->name('characters.class-features.update');
    // Speaker profiles (campaign-scoped)
    Route::post('speakers',                    [SpeakerProfileController::class, 'store'])->name('speakers.store');
    Route::patch('speakers/{speaker}',         [SpeakerProfileController::class, 'update'])->name('speakers.update');
    Route::delete('speakers/{speaker}',        [SpeakerProfileController::class, 'destroy'])->name('speakers.destroy');
});

// Encounters (accessible directly by id)
Route::resource('encounters', EncounterController::class)->only(['index', 'show', 'update', 'destroy']);

// Transcription
Route::prefix('sessions/{session}')->name('sessions.')->group(function () {
    // Chunked audio upload (replaces the old single-blob record/stop flow)
    Route::post('record/init',     [ChunkedAudioController::class, 'init'])->name('record.init');
    Route::post('record/chunk',    [ChunkedAudioController::class, 'chunk'])->name('record.chunk');
    Route::post('record/finalize', [ChunkedAudioController::class, 'finalize'])->name('record.finalize');
    // Import an existing audio file
    Route::post('import-audio',    [ChunkedAudioController::class, 'importAudio'])->name('import-audio');
    // Download the stored audio file
    Route::get('download-audio',   [ChunkedAudioController::class, 'downloadAudio'])->name('download-audio');
    Route::post('transcribe', [TranscriptionController::class, 'transcribe'])->name('transcribe');
    Route::post('generate-summary', [TranscriptionController::class, 'generateSummary'])->name('generate-summary');
    Route::get('summary-status', [TranscriptionController::class, 'summaryStatus'])->name('summary-status');
    Route::get('transcription-status',  [TranscriptionController::class, 'transcriptionStatus'])->name('transcription-status');
    Route::delete('transcription',      [TranscriptionController::class, 'cancelTranscription'])->name('transcription.cancel');
    Route::post('generate-art-prompts',  [TranscriptionController::class, 'generateArtPrompts'])->name('generate-art-prompts');
    Route::get('art-prompts-status',     [TranscriptionController::class, 'artPromptsStatus'])->name('art-prompts-status');
    Route::delete('art-prompts',         [TranscriptionController::class, 'cancelArtPrompts'])->name('art-prompts.cancel');
    Route::post('extract-details',       [TranscriptionController::class, 'extractDetails'])->name('extract-details');
    Route::get('extraction-status',      [TranscriptionController::class, 'extractionStatus'])->name('extraction-status');
    Route::post('live-sheet-updates',    [LiveSheetUpdateController::class, 'apply'])->name('live-sheet-updates');
    // Speaker profiles (session-scoped — WhisperX labels are per-session)
    Route::post('speakers',                [SpeakerProfileController::class, 'storeForSession'])->name('speakers.store');
    Route::patch('speakers/{speaker}',     [SpeakerProfileController::class, 'update'])->name('speakers.update');
    Route::delete('speakers/{speaker}',    [SpeakerProfileController::class, 'destroy'])->name('speakers.destroy');
    Route::delete('speakers',              [SpeakerProfileController::class, 'reset'])->name('speakers.reset');
});

// Batch character sheet PDF export
Route::get('batch-sheets', [BatchSheetController::class, 'index'])->name('batch-sheets.index');
Route::post('batch-sheets/export', [BatchSheetController::class, 'export'])->name('batch-sheets.export');

// PDF export
Route::post('campaigns/{campaign}/export-pdf', [PdfExportController::class, 'campaign'])
    ->name('campaigns.export-pdf');
Route::post('campaigns/{campaign}/sessions/{session}/export-pdf', [PdfExportController::class, 'session'])
    ->name('sessions.export-pdf');
Route::get('pdf-export/status', [PdfExportController::class, 'status'])
    ->name('pdf-export.status');
Route::get('pdf-export/preview', [PdfExportController::class, 'preview'])
    ->name('pdf-export.preview');
Route::post('pdf-export/print', [PdfExportController::class, 'print'])
    ->name('pdf-export.print');

// Oracle
Route::get('oracle', [OracleController::class, 'index'])->name('oracle.index');
Route::post('oracle/ask', [OracleController::class, 'ask'])->name('oracle.ask');
Route::get('oracle/replies/{reply}', [OracleController::class, 'replyStatus'])->name('oracle.reply-status');

// Settings
Route::get('settings', [AppSettingController::class, 'index'])->name('settings.index');
Route::post('settings', [AppSettingController::class, 'update'])->name('settings.update');
Route::get('settings/audio-capture', [AppSettingController::class, 'audioCapture'])->name('settings.audio-capture');

// Serve files stored on the local disk (portraits, party photos, etc.)
Route::get('storage-file/{path}', [StorageFileController::class, 'serve'])
    ->where('path', '.+')
    ->name('storage-file');

