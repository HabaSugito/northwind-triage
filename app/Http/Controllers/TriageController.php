<?php

namespace App\Http\Controllers;

use App\Services\TriageAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exposes the triage agent over HTTP.
 * ValidationException is caught by Handler.php and converted to HTTP 400
 * (Laravel's default would be 422, which doesn't match the API contract).
 */
class TriageController extends Controller
{
    public function __construct(
        private readonly TriageAgentService $agent
    ) {
    }

    /**
     * POST /api/triage — classify and respond to a single inbound customer message.
     * Only `body` is required; all other fields are optional context for the agent.
     */
    public function triage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1'],
            'channel' => ['nullable', 'string', 'in:email,webform,sms'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:500'],
            'received_at' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->agent->triage($validated);

            return response()->json($result);
        } catch (Throwable $e) {
            // Log the message body so support can reproduce the failure without re-running the batch.
            Log::error('Triage agent error', [
                'message' => $e->getMessage(),
                'body' => $request->input('body'),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
