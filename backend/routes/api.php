<?php

use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AgentBuildController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentTriggerController;
use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\FeatureRequestController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\FlowEditorController;
use App\Http\Controllers\Api\FlowVersionController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\Internal\AgentRunController as InternalAgentRunController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PreferencesController;
use App\Http\Controllers\Api\ProposalController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RunsController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\VoiceController;
use App\Models\Execution;
use App\Services\Flows\ExecutionException;
use App\Services\Flows\ExecutionService;
use Illuminate\Support\Facades\Route;

// Çalışma: yerel kayıt id'si (sayı) veya Kestra çalışma id'si. Kestra'da zamanlayıcı/webhook ile başlamış
// ve Fortis'te kaydı olmayan çalışma ilk açılışta içe aktarılır (sadece Fortis'in akışları).
Route::bind('execution', function (string $value) {
    if (ctype_digit($value)) {
        return Execution::findOrFail((int) $value);
    }

    try {
        return Execution::firstWhere('kestra_execution_id', $value) ?? app(ExecutionService::class)->import($value) ?? abort(404);
    } catch (ExecutionException $e) {
        abort(502, $e->getMessage());
    }
});

// Girişsiz açık uçlar: giriş ekranının ayarı ve giriş. Entra ID yönlendirmeleri routes/web.php'de.
Route::get('auth/config', [AuthController::class, 'config'])->name('auth.config');
Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

// Kestra'nın çağırdığı iç uçlar: giriş yerine X-Fortis-Key.
Route::middleware(['internal.key', 'throttle:60,1'])->prefix('internal')->name('internal.')->group(function () {
    Route::post('agents/{agent}/runs', [InternalAgentRunController::class, 'store'])->whereNumber('agent')->name('agents.runs.store');
});

// Diğer her uç giriş ister (Kestra'nın kullanacağı /api/internal/* uçları hariç; onlar X-Fortis-Key ile korunur).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::patch('me/preferences', [PreferencesController::class, 'update'])->name('me.preferences');
    Route::post('me/password', [PreferencesController::class, 'password'])->name('me.password');
});

Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {
    // Yönetim (admin)
    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
        Route::apiResource('departments', DepartmentController::class)->only(['index', 'store', 'update']);
    });

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store']);
    Route::middleware('can:view,conversation')->group(function () {
        Route::apiResource('conversations', ConversationController::class)->only(['show', 'update', 'destroy']);
        Route::post('conversations/{conversation}/messages', ConversationMessageController::class)->name('conversations.messages.store');
    });
    Route::post('flow-versions/{flowVersion}/upload', [FlowVersionController::class, 'upload'])->middleware('can:build')->name('flow-versions.upload');

    Route::post('flows/{flow}/executions', [ExecutionController::class, 'store'])->name('flows.executions.store');
    Route::get('executions', [RunsController::class, 'index'])->name('executions.index');
    Route::get('executions/{execution}/logs', [RunsController::class, 'logs'])->name('executions.logs');
    Route::post('executions/{execution}/kill', [RunsController::class, 'kill'])->name('executions.kill');
    Route::post('executions/{execution}/explain', [RunsController::class, 'explain'])->name('executions.explain');
    Route::get('executions/{execution}', [ExecutionController::class, 'show'])->name('executions.show');
    Route::get('executions/{execution}/stream', [ExecutionController::class, 'stream'])->name('executions.stream');
    Route::post('executions/{execution}/resume', [ExecutionController::class, 'resume'])->name('executions.resume');

    Route::get('flows', [FlowController::class, 'index'])->name('flows.index');
    Route::get('flows/{flow}', [FlowController::class, 'show'])->name('flows.show');
    Route::post('flows/{flow}/enable', [FlowController::class, 'enable'])->middleware('can:build')->name('flows.enable');
    Route::post('flows/{flow}/disable', [FlowController::class, 'disable'])->middleware('can:build')->name('flows.disable');
    Route::delete('flows/{flow}', [FlowController::class, 'destroy'])->middleware('can:build')->name('flows.destroy');
    Route::post('flows/{flow}/edit-in-chat', [FlowController::class, 'editInChat'])->middleware('can:build')->name('flows.edit-in-chat');

    Route::get('feature-requests', [FeatureRequestController::class, 'index'])->name('feature-requests.index');
    Route::patch('feature-requests/{featureRequest}', [FeatureRequestController::class, 'update'])->name('feature-requests.update');

    // Görsel akış editörü
    Route::get('catalog/blocks', [FlowEditorController::class, 'catalog'])->name('catalog.blocks');
    Route::get('apps', [FlowEditorController::class, 'catalog'])->name('apps.index');
    Route::get('flows/{flow}/editor', [FlowEditorController::class, 'show'])->name('flows.editor');
    Route::post('flows/{flow}/operations', [FlowEditorController::class, 'operations'])->middleware('can:build')->name('flows.operations');
    Route::put('flows/{flow}/yaml', [FlowEditorController::class, 'updateYaml'])->middleware('can:build')->name('flows.yaml');
    Route::post('flows/{flow}/publish', [FlowEditorController::class, 'publish'])->middleware('can:build')->name('flows.publish');
    Route::post('flows/{flow}/test-run', [FlowEditorController::class, 'testRun'])->middleware('can:build')->name('flows.test-run');
    Route::get('flows/{flow}/versions', [FlowEditorController::class, 'versions'])->name('flows.versions');
    Route::get('flows/{flow}/versions/{number}/diff', [FlowEditorController::class, 'diff'])->whereNumber('number')->name('flows.versions.diff');
    Route::post('flows/{flow}/versions/{number}/restore', [FlowEditorController::class, 'restore'])->whereNumber('number')->middleware('can:build')->name('flows.versions.restore');
    Route::post('flows/{flow}/editor-conversation', [FlowEditorController::class, 'conversation'])->middleware('can:build')->name('flows.editor-conversation');

    Route::get('proposals/{proposal}', [ProposalController::class, 'show'])->name('proposals.show');
    Route::post('proposals/{proposal}/apply', [ProposalController::class, 'apply'])->middleware('can:build')->name('proposals.apply');
    Route::post('proposals/{proposal}/reject', [ProposalController::class, 'reject'])->middleware('can:build')->name('proposals.reject');

    // Model seçimi, kullanım ve maliyet
    Route::get('settings/ai', [SettingsController::class, 'show'])->name('settings.ai.show');
    Route::put('settings/ai', [SettingsController::class, 'update'])->middleware('can:admin')->name('settings.ai.update');
    Route::get('usage/summary', [UsageController::class, 'summary'])->name('usage.summary');
    Route::get('usage/daily', [UsageController::class, 'daily'])->name('usage.daily');
    Route::get('usage/by-model', [UsageController::class, 'byModel'])->name('usage.by-model');
    Route::get('usage/by-flow', [UsageController::class, 'byFlow'])->name('usage.by-flow');
    Route::get('usage/export.csv', [UsageController::class, 'export'])->name('usage.export');

    // Raporlar (Excel / CSV / PDF)
    Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('can:view,report')->name('reports.show');
    Route::post('reports/{report}/download-link', [ReportController::class, 'downloadLink'])->middleware('can:view,report')->name('reports.download-link');
    Route::post('reports/{report}/regenerate', [ReportController::class, 'regenerate'])->middleware('can:view,report')->name('reports.regenerate');
    Route::get('reports/{report}/download', [ReportController::class, 'download'])->middleware(['signed:relative', 'can:view,report'])->name('reports.download');

    // Sohbette üretilen arayüz, diyagram ve belgeler
    Route::get('artifacts/{artifact}', [ArtifactController::class, 'show'])->middleware('can:view,artifact')->name('artifacts.show');

    // Home ve konuşma ekranları
    Route::get('home', HomeController::class)->name('home');
    Route::get('connectors/status', [ConnectorController::class, 'status'])->name('connectors.status');
    Route::post('messages/{message}/feedback', [MessageController::class, 'feedback'])->name('messages.feedback');
    Route::get('messages/{message}/audit', [MessageController::class, 'audit'])->name('messages.audit');

    // Agentlar (A3.1: liste, elle çalıştırma, çalışma ayrıntısı)
    Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
    Route::get('agents/catalog', [AgentController::class, 'catalog'])->name('agents.catalog');
    Route::get('agents/{agent}', [AgentController::class, 'show'])->whereNumber('agent')->name('agents.show');
    Route::put('agents/{agent}', [AgentController::class, 'update'])->whereNumber('agent')->name('agents.update');
    Route::get('agents/{agent}/runs', [AgentController::class, 'runs'])->name('agents.runs.index');
    Route::post('agents/{agent}/runs', [AgentController::class, 'run'])->name('agents.runs.store');
    Route::get('agents/{agent}/chats', [AgentController::class, 'chats'])->name('agents.chats.index');
    Route::post('agents/{agent}/chats', [AgentController::class, 'chat'])->name('agents.chats.store');
    Route::get('agents/{agent}/versions', [AgentController::class, 'versions'])->name('agents.versions');
    Route::post('agents/{agent}/versions/{number}/restore', [AgentController::class, 'restore'])->whereNumber('number')->name('agents.versions.restore');
    Route::get('agent-runs/{run}', [AgentController::class, 'showRun'])->name('agent-runs.show');
    Route::post('agent-runs/{run}/feedback', [AgentController::class, 'feedback'])->name('agent-runs.feedback');
    Route::post('agent-runs/{run}/score', [AgentController::class, 'score'])->name('agent-runs.score');
    Route::get('agents/{agent}/evaluation', [AgentController::class, 'evaluation'])->name('agents.evaluation');
    Route::put('agents/{agent}/evaluation', [AgentController::class, 'updateEvaluation'])->name('agents.evaluation.update');
    // Oluştur ve Architect (A3.3)
    Route::middleware('can:build')->group(function () {
        Route::get('build', [AgentBuildController::class, 'index'])->name('build.index');
        Route::post('agents', [AgentBuildController::class, 'store'])->name('agents.store');
        Route::post('agents/from-conversation', [AgentBuildController::class, 'fromConversation'])->name('agents.from-conversation');
        Route::get('agents/{agent}/architect', [AgentBuildController::class, 'show'])->name('agents.architect');
        Route::post('agents/{agent}/status', [AgentBuildController::class, 'status'])->name('agents.status');
    });
    Route::get('agents/{agent}/triggers', [AgentTriggerController::class, 'index'])->name('agents.triggers.index');
    Route::post('agents/{agent}/triggers', [AgentTriggerController::class, 'store'])->name('agents.triggers.store');
    Route::patch('agent-triggers/{trigger}', [AgentTriggerController::class, 'update'])->name('agent-triggers.update');
    Route::delete('agent-triggers/{trigger}', [AgentTriggerController::class, 'destroy'])->name('agent-triggers.destroy');

    // Inbox: onaylar, agent soruları, güncellemeler, uyarılar
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/count', [InboxController::class, 'count'])->name('inbox.count');
    Route::post('inbox/bulk', [InboxController::class, 'bulk'])->name('inbox.bulk');
    Route::get('inbox/{item}', [InboxController::class, 'show'])->whereNumber('item')->name('inbox.show');
    Route::post('inbox/{item}/approve', [InboxController::class, 'approve'])->name('inbox.approve');
    Route::post('inbox/{item}/reject', [InboxController::class, 'reject'])->name('inbox.reject');
    Route::post('inbox/{item}/answer', [InboxController::class, 'answer'])->name('inbox.answer');
    Route::patch('inbox/{item}/draft', [InboxController::class, 'draft'])->name('inbox.draft');
    Route::post('inbox/{item}/done', [InboxController::class, 'done'])->name('inbox.done');
    Route::post('inbox/{item}/snooze', [InboxController::class, 'snooze'])->name('inbox.snooze');

    // Sesli komut (ses → metin, şirket içi Whisper)
    Route::get('voice/config', [VoiceController::class, 'config'])->name('voice.config');
    Route::post('voice/transcribe', [VoiceController::class, 'transcribe'])->middleware('throttle:voice')->name('voice.transcribe');
});
