<?php

namespace App\Http\Controllers;

use App\Jobs\AskOracle;
use App\Models\AppSetting;
use App\Models\Campaign;
use App\Models\GameSession;
use App\Models\OracleReply;
use App\Support\Adnd2eOracleBriefing;
use App\Support\Adnd2eOracleRulesLookup;
use App\Support\SessionSheetUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OracleController extends Controller
{
    public function index(): Response
    {
        $campaigns = Campaign::with(['characters', 'npcs', 'gameSessions' => fn ($q) => $q->latest()->limit(5)])->get();
        $provider  = AppSetting::get('llm_provider', 'none');

        return Inertia::render('Oracle/Index', [
            'campaigns' => $campaigns,
            'hasLlm'    => $provider !== 'none',
        ]);
    }

    /**
     * Dispatch the Oracle job and return the reply ID for polling.
     */
    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'messages'           => 'required|array|min:1',
            'messages.*.role'    => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:8000',
            'context'            => 'nullable|array',
            'session_id'         => 'nullable|integer|exists:game_sessions,id',
        ]);

        $sheetUpdated = false;
        $messages = $request->input('messages');
        $lastUser = collect($messages)
            ->reverse()
            ->first(fn ($m) => ($m['role'] ?? '') === 'user');
        $question = is_array($lastUser) ? (string) ($lastUser['content'] ?? '') : '';

        $sessionId = $request->input('session_id');
        if ($sessionId) {
            $session = GameSession::find($sessionId);
            if ($session && $question !== '') {
                $sheetUpdated = SessionSheetUpdates::applyFromText(
                    $session,
                    $question,
                    'oracle-'.uniqid('', true)
                );
            }
        }

        $context = $request->input('context', []);
        $context = is_array($context) ? $context : [];
        $direct = $question !== ''
            ? Adnd2eOracleRulesLookup::playerReply($question, $context)
            : null;

        if ($direct !== null) {
            $reply = OracleReply::create(['status' => 'done', 'reply' => $direct]);
            Log::info('Oracle engine table answer', [
                'reply_id' => $reply->id,
                'question' => $question,
            ]);

            return response()->json([
                'reply_id' => $reply->id,
                'sheet_updated' => $sheetUpdated,
            ]);
        }

        $provider = AppSetting::get('llm_provider', 'none');

        if ($provider === 'none') {
            return response()->json([
                'error' => 'No LLM provider configured. Set one in Settings.',
                'sheet_updated' => $sheetUpdated,
            ], 422);
        }

        $systemPrompt = $this->buildSystemPrompt($context, $question);

        $reply = OracleReply::create(['status' => 'pending']);

        AskOracle::dispatch($reply, $systemPrompt, $messages);

        return response()->json([
            'reply_id' => $reply->id,
            'sheet_updated' => $sheetUpdated,
        ]);
    }

    /**
     * Poll for a reply's status and result.
     */
    public function replyStatus(OracleReply $reply): JsonResponse
    {
        return response()->json([
            'status' => $reply->status,
            'reply'  => $reply->reply,
        ]);
    }

    // ── System prompt ──────────────────────────────────────────────────────

    public function buildSystemPrompt(array $context, ?string $question = null): string
    {
        return Adnd2eOracleBriefing::systemPrompt($context, $question);
    }
}
