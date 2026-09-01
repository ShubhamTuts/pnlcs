<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\AiAgentMessage;
use App\Services\Webkahost\AiCreditService;
use App\Services\Webkahost\WebkahostAgent;
use Illuminate\Http\Request;

class WebkahostAgentController extends Controller
{
    use ResolvesClient;

    public function show(AiCreditService $credits)
    {
        $client = $this->currentClient();
        abort_unless($client, 403);

        $history = AiAgentMessage::where('client_id', $client->id)
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->values();

        return view('client.ai.agent', [
            'client' => $client,
            'balance' => $credits->balance($client),
            'history' => $history,
        ]);
    }

    public function message(Request $request, WebkahostAgent $agent)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        $client = $this->currentClient();
        abort_unless($client, 403);

        $result = $agent->chat($client, $validated['message']);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return back()->with('agent_reply', $result['reply']);
    }
}
