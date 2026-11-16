<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\ChatSession;
use App\Services\Ai\Assistant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** الدردشة مع المساعد: جلسات مستقلة، كل جلسة لصاحبها فقط. */
class ChatController extends Controller
{
    public function index(Request $request, ?ChatSession $session = null): Response
    {
        Gate::authorize(Permission::Chat->value);
        if ($session) {
            $this->own($request, $session);
        }

        return Inertia::render('chat/Index', [
            'sessions' => $request->user()->chatSessions()->latest('updated_at')->limit(100)->get(['id', 'title', 'updated_at']),
            'current' => $session ? ['id' => $session->id, 'title' => $session->title,
                'messages' => $session->messages()->get(['id', 'role', 'content', 'created_at'])] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::Chat->value);
        $session = ChatSession::query()->create(['user_id' => $request->user()->id]);

        return to_route('chat.show', $session);
    }

    public function send(Request $request, ChatSession $session, Assistant $assistant): RedirectResponse
    {
        Gate::authorize(Permission::Chat->value);
        $this->own($request, $session);
        $text = $request->validate(['message' => ['required', 'string', 'max:2000']])['message'];

        try {
            $assistant->reply($session, trim($text));
        } catch (\Throwable $e) {
            // the question is stored; say in the conversation that no answer came, instead of leaving it hanging
            report($e);
            $session->messages()->create(['role' => 'assistant', 'content' => __('ui.chat.failed')]);
        }

        return to_route('chat.show', $session);
    }

    public function rename(Request $request, ChatSession $session): RedirectResponse
    {
        $this->own($request, $session);
        $session->update($request->validate(['title' => ['required', 'string', 'max:80']]));

        return back();
    }

    public function destroy(Request $request, ChatSession $session): RedirectResponse
    {
        $this->own($request, $session);
        $session->delete();

        return to_route('chat');
    }

    /** A session is private to the one who opened it; anyone else gets a 404, not a 403. */
    private function own(Request $request, ChatSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }
}
