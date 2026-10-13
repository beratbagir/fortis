<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Editör sohbetleri akış editöründe açılır; sohbet listesinde görünmez.
        // Liste kullanıcının kendi sohbetleri; admin ?all=1 ile hepsini görebilir.
        return ConversationResource::collection(Conversation::query()
            ->where('kind', Conversation::KIND_CHAT)
            ->unless($request->boolean('all') && $request->user()->isAdmin(), fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest('updated_at')->latest('id')->get());
    }

    public function store(Request $request): ConversationResource
    {
        $validated = $request->validate(['title' => ['nullable', 'string', 'max:255']]);

        return new ConversationResource(Conversation::create($validated)->load('messages'));
    }

    public function show(Conversation $conversation): ConversationResource
    {
        return new ConversationResource($conversation->load('messages'));
    }

    public function update(Request $request, Conversation $conversation): ConversationResource
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            // İzinli liste dışındaki model 422 döner. "auto" (veya null): ModelRouter her tur için seçer.
            'model' => ['sometimes', 'nullable', 'string', Rule::in([Conversation::MODEL_AUTO, ...array_keys(config('ai.models'))])],
            // Kaynak seçici: bu sohbette kapatılan kaynaklar.
            'disabled_sources' => ['sometimes', 'array'],
            'disabled_sources.*' => ['string', Rule::in(array_keys(config('connectors.sources')))],
        ], ['model.in' => 'Bu model kullanılamaz. İzinli modeller: '.implode(', ', array_keys(config('ai.models'))).'.']);

        if (($validated['model'] ?? null) === Conversation::MODEL_AUTO) {
            $validated['model'] = null;
        }
        if (isset($validated['disabled_sources'])) {
            $validated['disabled_sources'] = array_values(array_unique($validated['disabled_sources']));
        }
        // Kullanıcı yeniden adlandırdıysa üretilen başlık üzerine yazılmaz.
        if (isset($validated['title'])) {
            $validated['title_generated_at'] = now();
        }

        $conversation->update($validated);

        return new ConversationResource($conversation);
    }

    public function destroy(Conversation $conversation): Response
    {
        $conversation->delete();

        return response()->noContent();
    }
}
