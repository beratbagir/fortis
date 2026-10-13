<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentActions;
use App\Agents\AgentRunner;
use App\Agents\Inbox;
use App\Http\Controllers\Controller;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\Execution;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Flows\ExecutionException;
use App\Services\Flows\ExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inbox: kullanıcının onayları, agent soruları, güncellemeleri ve uyarıları. Kullanıcı sadece kendi öğelerini görür.
 */
class InboxController extends Controller
{
    private const TABS = ['all', 'action', 'mentions', 'updates', 'done'];

    public function __construct(
        private readonly AgentActions $actions,
        private readonly AgentRunner $runner,
        private readonly Inbox $inbox,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';
        $mine = fn () => InboxItem::query()->where('user_id', $request->user()->id);

        return response()->json([
            'data' => self::tab($mine(), $tab)->latest('id')->limit(200)->get()->map(self::present(...))->values(),
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $t) => [$t => self::tab($mine(), $t)->count()]),
        ]);
    }

    /** Kenar çubuğu rozeti: açık "aksiyon gerekiyor" sayısı (onay + soru). */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['data' => ['action' => self::tab(InboxItem::query()->where('user_id', $request->user()->id), 'action')->count()]]);
    }

    public function show(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        $item->read_at ??= now();
        $item->save();

        return response()->json(['data' => self::present($item) + ['detail' => $this->detail($item, $request->user())]]);
    }

    public function approve(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        $this->decide($item, $request->user(), true, null);

        return $this->show($request, $item->refresh());
    }

    public function reject(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->decide($item, $request->user(), false, $validated['reason'] ?? null);

        return $this->show($request, $item->refresh());
    }

    /** Agent sorusunun cevabı: çalışma kaldığı yerden devam eder. */
    public function answer(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        abort_unless($item->type === InboxItem::QUESTION && $item->subject_type === Inbox::SUBJECT_RUN, 422, 'Bu öğe bir soru değil.');

        $validated = $request->validate([
            'answer' => ['required', 'array'],
            'answer.skipped' => ['sometimes', 'boolean'],
            'answer.answers' => ['required_unless:answer.skipped,true', 'array', 'max:3'],
            'answer.answers.*.question' => ['required', 'string', 'max:300'],
            'answer.answers.*.selected' => ['present', 'array', 'max:5'],
            'answer.answers.*.selected.*' => ['string', 'max:200'],
            'answer.answers.*.other' => ['nullable', 'string', 'max:500'],
        ]);

        $this->runner->answer(AgentRun::query()->findOrFail($item->subject_id), $validated['answer'], $request->user());

        return $this->show($request, $item->refresh());
    }

    /** Taslağı düzenle: konu, gövde; alıcılar sadece izinli alıcılar arasından. */
    public function draft(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        abort_unless($item->subject_type === Inbox::SUBJECT_ACTION, 422, 'Bu öğede düzenlenecek taslak yok.');

        $action = AgentAction::query()->findOrFail($item->subject_id);
        abort_unless($this->actions->canDecide($action, $request->user()), 403, 'Bu aksiyonu düzenleme yetkin yok.');

        $validated = $request->validate([
            'to' => ['sometimes', 'array', 'min:1', 'max:20'],
            'to.*' => ['string', 'email'],
            'subject' => ['sometimes', 'string', 'max:300'],
            'body_markdown' => ['sometimes', 'string', 'max:50000'],
            'text_markdown' => ['sometimes', 'string', 'max:20000'],
            'message_markdown' => ['sometimes', 'string', 'max:20000'],
        ]);

        $result = $this->actions->editDraft($action, $validated);
        abort_if(is_string($result), 422, is_string($result) ? $result : '');
        Audit::record('agent.action.edit', $action, meta: ['fields' => array_keys($validated)]);

        return $this->show($request, $item);
    }

    public function done(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        $item->update(['status' => InboxItem::DONE, 'read_at' => $item->read_at ?? now()]);

        return response()->json(['data' => self::present($item)]);
    }

    public function snooze(Request $request, InboxItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        $item->update(['status' => InboxItem::SNOOZED, 'snoozed_until' => self::until($request)]);

        return response()->json(['data' => self::present($item)]);
    }

    /** Toplu işlem: tamamlandı, ertele, hepsini onayla (sadece hepsi onay türündeyse ve yetki varsa). */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'op' => ['required', Rule::in(['done', 'snooze', 'approve'])],
            'until' => ['sometimes', 'string'],
        ]);

        $items = InboxItem::query()->where('user_id', $request->user()->id)->whereIn('id', $validated['ids'])->get();
        abort_if($items->count() !== count(array_unique($validated['ids'])), 404);

        if ($validated['op'] === 'approve') {
            abort_unless($items->every(fn (InboxItem $i) => $i->type === InboxItem::APPROVAL && $i->isActionable()), 422, 'Hepsini onayla sadece açık onay öğelerinde kullanılabilir.');
            abort_unless($items->every(fn (InboxItem $i) => $this->canDecide($i, $request->user())), 403, 'Seçilenlerin bazılarını onaylama yetkin yok.');
            $items->each(fn (InboxItem $i) => $this->decide($i, $request->user(), true, null));
        } elseif ($validated['op'] === 'done') {
            InboxItem::query()->whereIn('id', $items->pluck('id'))->update(['status' => InboxItem::DONE, 'read_at' => now()]);
        } else {
            InboxItem::query()->whereIn('id', $items->pluck('id'))->update(['status' => InboxItem::SNOOZED, 'snoozed_until' => self::until($request)]);
        }

        return response()->json(['data' => ['updated' => $items->count()]]);
    }

    private function decide(InboxItem $item, User $user, bool $approve, ?string $reason): void
    {
        abort_unless($item->type === InboxItem::APPROVAL, 422, 'Bu öğe bir onay değil.');
        abort_unless($this->canDecide($item, $user), 403, 'Bu aksiyonu onaylama yetkin yok.');

        if ($item->subject_type === Inbox::SUBJECT_ACTION) {
            $action = AgentAction::query()->findOrFail($item->subject_id);
            abort_unless($action->status === AgentAction::PENDING, 409, 'Bu aksiyon zaten sonuçlandı.');
            $approve ? $this->actions->approve($action, $user) : $this->actions->reject($action, $user, $reason);

            return;
        }

        if ($item->subject_type === Inbox::SUBJECT_EXECUTION) {
            $execution = Execution::query()->findOrFail($item->subject_id);
            try {
                app(ExecutionService::class)->resume($execution, $approve, []);
            } catch (ExecutionException $e) {
                abort(422, $e->getMessage());
            }
            Audit::record($approve ? 'execution.resume' : 'execution.reject', $execution, meta: ['flow_id' => $execution->flow_id, 'via' => 'inbox'], system: 'Kestra', user: $user);
            $this->inbox->close(Inbox::SUBJECT_EXECUTION, $execution->id);
        }
    }

    private function canDecide(InboxItem $item, User $user): bool
    {
        return match ($item->subject_type) {
            Inbox::SUBJECT_ACTION => $this->actions->canDecide(AgentAction::query()->findOrFail($item->subject_id), $user),
            Inbox::SUBJECT_EXECUTION => $user->isAdmin() || Execution::query()->find($item->subject_id)?->flow?->owner_id === $user->id,
            default => false,
        };
    }

    private function authorizeItem(Request $request, InboxItem $item): void
    {
        abort_unless($item->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function detail(InboxItem $item, User $user): array
    {
        return match ($item->subject_type) {
            Inbox::SUBJECT_ACTION => $this->actionDetail(AgentAction::query()->with('run.agent.owner')->findOrFail($item->subject_id), $user),
            Inbox::SUBJECT_RUN => $this->runDetail(AgentRun::query()->with('agent')->findOrFail($item->subject_id)),
            Inbox::SUBJECT_EXECUTION => $this->executionDetail(Execution::query()->with('flow')->findOrFail($item->subject_id), $user),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function actionDetail(AgentAction $action, User $user): array
    {
        $p = $action->payload;
        $from = config('agents.mail_from.address');
        $steps = match ($action->type) {
            'send_email' => [
                "E-posta {$from} adresinden ".implode(', ', $p['to'] ?? []).' adresine gönderilir.',
                ...(! empty($p['attach_report_id']) ? ['Ekli rapor alıcılarla paylaşılır.'] : []),
            ],
            'post_teams' => ($p['deliver_as_email'] ?? false)
                ? ['Agent pilotta olduğu için mesaj Teams yerine sana e-postayla gelir.']
                : ["Mesaj Teams'te #{$p['channel']} kanalına gönderilir."],
            'deliver_report' => ["Rapor dosyası e-posta eki olarak {$from} adresinden ".implode(', ', $p['to'] ?? []).' adresine gönderilir.'],
            default => [],
        };
        $steps[] = 'Bu işlem senin adınla denetim kaydına yazılır.';

        return [
            'kind' => 'action',
            'action' => [
                'id' => $action->id,
                'type' => $action->type,
                'status' => $action->status,
                'payload' => $p,
                'reason' => $action->reason,
                'result' => $action->result,
            ],
            'run' => $this->runSummary($action->run),
            'what_happens' => $steps,
            'can_decide' => $this->actions->canDecide($action, $user),
            'editable_recipients' => ! $action->run->agent->isPilot(),
            'recipient_domains' => config('agents.allowed_recipient_domains'),
            'allowed_recipients' => $action->run->agent->toolConstraints($action->type)['recipients'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function runDetail(AgentRun $run): array
    {
        $pending = $run->conversation?->messages()->where('role', Message::ROLE_ASSISTANT)->latest('id')->first();

        return [
            'kind' => 'run',
            'run' => $this->runSummary($run) + ['error' => $run->error],
            'questions' => $run->status === AgentRun::WAITING_INPUT && $pending?->isAwaitingInput() ? ($pending->meta['ask']['questions'] ?? []) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function executionDetail(Execution $execution, User $user): array
    {
        return [
            'kind' => 'execution',
            'execution' => ['id' => $execution->kestra_execution_id, 'flow' => $execution->flow?->title, 'state' => $execution->state, 'started_at' => $execution->started_at?->toIso8601String()],
            'what_happens' => ['Onaylarsan akış kaldığı yerden devam eder.', 'Reddedersen akış durdurulur (onay girdisi varsa "hayır" olarak devam eder).', 'Karar senin adınla denetim kaydına yazılır.'],
            'can_decide' => $user->isAdmin() || $execution->flow?->owner_id === $user->id,
        ];
    }

    /** @return array<string, mixed> */
    private function runSummary(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'agent' => ['id' => $run->agent->id, 'name' => $run->agent->name, 'status' => $run->agent->status, 'icon' => $run->agent->icon],
            'status' => $run->status,
            'is_test' => $run->is_test,
            'started_at' => $run->started_at?->toIso8601String(),
            'summary' => $run->summary,
            'output' => $run->output,
            'conversation_id' => $run->conversation_id,
            'cost_usd' => (float) $run->cost_usd,
        ];
    }

    /** @return array<string, mixed> */
    public static function present(InboxItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'body' => $item->body,
            'source_label' => $item->source_label,
            'icon' => $item->icon,
            'status' => $item->status === InboxItem::SNOOZED && $item->snoozed_until?->isPast() ? InboxItem::OPEN : $item->status,
            'actionable' => $item->isActionable(),
            'subject_type' => $item->subject_type,
            'snoozed_until' => $item->snoozed_until?->toIso8601String(),
            'read' => $item->read_at !== null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    private static function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'done' => $query->where('status', InboxItem::DONE),
            'action' => $query->active()->whereIn('type', InboxItem::ACTION_TYPES),
            'mentions' => $query->active()->where('type', InboxItem::MENTION),
            'updates' => $query->active()->whereIn('type', [InboxItem::UPDATE, InboxItem::ALERT, InboxItem::SHARED]),
            default => $query->active(),
        };
    }

    /** Erteleme: 1h, 4h, tomorrow (yarın 08:00 TSİ) veya ISO tarih. */
    private static function until(Request $request): CarbonImmutable
    {
        $until = (string) $request->input('until', '1h');
        $tz = 'Europe/Istanbul';

        return match ($until) {
            '1h' => CarbonImmutable::now()->addHour(),
            '4h' => CarbonImmutable::now()->addHours(4),
            'tomorrow' => CarbonImmutable::now($tz)->addDay()->setTime(8, 0)->utc(),
            default => rescue(fn () => CarbonImmutable::parse($until), CarbonImmutable::now()->addHour(), false),
        };
    }
}
