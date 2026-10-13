<?php

namespace App\Agents;

use App\Mail\AgentEmail;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Claude\ToolResult;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Agent'ın yazma araçları (send_email, post_teams, deliver_report). Araç çağrısı doğrudan çalışmaz: agent_actions
 * kaydı oluşur; onay politikası izin veriyorsa hemen, vermiyorsa Inbox'ta onaylanınca bir kez çalışır.
 *
 * Kurallar: alıcılar izinli alanlarla sınırlı (şirket dışı her zaman onay ister, admin politikasıyla tamamen kapalı
 * olabilir); pilotta her aksiyon onaylı ve sadece sahibine; test çalışmasında hiçbir aksiyon çalışmaz.
 */
class AgentActions
{
    public const TOOLS = ['send_email', 'post_teams', 'deliver_report'];

    public function __construct(private readonly Inbox $inbox) {}

    /** @return list<array<string, mixed>> Claude'a verilecek yazma aracı tanımları */
    public static function definitions(array $names): array
    {
        $reason = ['type' => 'string', 'description' => 'Bu aksiyonu neden önerdiğin, bir iki cümle (onaylayan kişi görür).'];
        $all = [
            'send_email' => [
                'name' => 'send_email',
                'description' => 'E-posta gönderir. Doğrudan gitmez: onay politikasına göre Inbox\'ta onaylanınca gönderilir. Sadece izinli alıcılara.',
                'eagerInputStreaming' => true,
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Alıcı e-posta adresleri. Çalışmayı başlatan kullanıcıya (talimatta "bana") göndermek için tam olarak KULLANICI_EPOSTASI yaz.'],
                    'cc' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'subject' => ['type' => 'string'],
                    'body_markdown' => ['type' => 'string', 'description' => 'Gövde, markdown.'],
                    'attach_report_id' => ['type' => 'string', 'description' => 'create_report ile üretilen rapor (isteğe bağlı).'],
                    'reason' => $reason,
                ], 'required' => ['to', 'subject', 'body_markdown', 'reason'], 'additionalProperties' => false],
            ],
            'post_teams' => [
                'name' => 'post_teams',
                'description' => 'İzinli bir Teams kanalına mesaj gönderir. Onay politikasına göre gider. Kanallar: '.(implode(', ', array_keys(config('agents.teams_channels'))) ?: 'tanımlı kanal yok').'.',
                'eagerInputStreaming' => true,
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'channel' => ['type' => 'string'],
                    'text_markdown' => ['type' => 'string'],
                    'reason' => $reason,
                ], 'required' => ['channel', 'text_markdown', 'reason'], 'additionalProperties' => false],
            ],
            'deliver_report' => [
                'name' => 'deliver_report',
                'description' => 'create_report ile üretilen dosyayı e-posta eki olarak gönderir. Onay politikasına göre gider.',
                'eagerInputStreaming' => true,
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'report_id' => ['type' => 'string'],
                    'to' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'subject' => ['type' => 'string'],
                    'message_markdown' => ['type' => 'string'],
                    'reason' => $reason,
                ], 'required' => ['report_id', 'to', 'reason'], 'additionalProperties' => false],
            ],
        ];

        return array_values(array_intersect_key($all, array_flip($names)));
    }

    /**
     * Yazma aracı çağrısı: kontrol eder, aksiyonu kaydeder; onay gerekmiyorsa çalıştırır.
     *
     * @param  array<string, mixed>  $input
     */
    public function request(AgentSession $session, string $type, array $input): ToolResult
    {
        $run = $session->run;
        $agent = $session->agent;
        $owner = $agent->owner ?? $run->ownerUser();

        $payload = $this->normalize($type, $input, $run->ownerUser()?->email);
        if (is_string($payload)) {
            return ToolResult::invalidInput($input, $payload);
        }

        $constraints = $agent->toolConstraints($type);
        $max = (int) ($constraints['max_per_run'] ?? 0);
        if ($max > 0 && $run->actions()->where('type', $type)->whereNotIn('status', [AgentAction::REJECTED])->count() >= $max) {
            return ToolResult::error("Bu çalışmada {$type} en fazla {$max} kez kullanılabilir.");
        }

        // Alıcılar: araç kısıtı (izinli liste) ve alan adı kuralı.
        $recipients = [...($payload['to'] ?? []), ...($payload['cc'] ?? [])];
        $allowedList = array_map('mb_strtolower', $constraints['recipients'] ?? []);
        if ($allowedList !== [] && ($outside = array_diff($recipients, $allowedList)) !== []) {
            return $this->refuse($session, $type, 'Bu alıcılar agent için izinli değil: '.implode(', ', $outside).'. İzinli: '.implode(', ', $allowedList).'.');
        }
        $external = array_values(array_filter($recipients, fn (string $email) => ! self::isInternal($email)));
        if ($external !== [] && ! app(AppSettings::class)->allowExternalRecipients()) {
            return $this->refuse($session, $type, 'Şirket dışı adreslere gönderim kapalı: '.implode(', ', $external).'.');
        }

        if ($type === 'post_teams' && ! array_key_exists($payload['channel'], config('agents.teams_channels'))) {
            return $this->refuse($session, $type, "'{$payload['channel']}' izinli bir Teams kanalı değil. İzinli: ".(implode(', ', array_keys(config('agents.teams_channels'))) ?: 'yok').'.');
        }

        // Pilot: sadece sahibine (asıl alıcılar önizlemede görünür).
        if ($agent->isPilot()) {
            $payload['intended'] = array_filter(['to' => $payload['to'] ?? null, 'cc' => $payload['cc'] ?? null, 'channel' => $payload['channel'] ?? null]);
            $payload['to'] = array_values(array_filter([$owner?->email]));
            $payload['cc'] = [];
            if ($type === 'post_teams') {
                $payload['deliver_as_email'] = true;
            }
        }

        $requiresApproval = $this->requiresApproval($session, $type, $external !== [], $payload);

        // Aynı çalışmada aynı türde bekleyen eski taslak geçersiz olur (agent yeni taslak üretti).
        foreach ($run->actions()->where('type', $type)->where('status', AgentAction::PENDING)->get() as $old) {
            $old->update(['status' => AgentAction::REJECTED, 'result' => ['note' => 'Yeni taslakla değiştirildi.']]);
            $this->inbox->close(Inbox::SUBJECT_ACTION, $old->id);
        }

        $action = $run->actions()->create([
            'type' => $type,
            'payload' => $payload,
            'reason' => is_string($input['reason'] ?? null) ? mb_substr(trim($input['reason']), 0, 1000) : null,
            'status' => $session->isTest() ? AgentAction::PREVIEW : AgentAction::PENDING,
            'requires_approval' => $requiresApproval,
        ]);

        $this->audit($run, 'agent.action.request', $action, AuditLog::RESULT_AWAITING_APPROVAL);

        if ($session->isTest()) {
            return ToolResult::json(['action_id' => $action->id, 'status' => 'preview', 'note' => 'Test çalışması: aksiyon gönderilmedi, sadece önizlendi. İşini bitir ve özetini yaz.'])
                ->withMeta(['action_id' => $action->id]);
        }

        if (! $requiresApproval) {
            $this->execute($action);

            return ToolResult::json(['action_id' => $action->id, 'status' => $action->refresh()->status, 'result' => $action->result])->withMeta(['action_id' => $action->id]);
        }

        foreach ($this->approvers($session) as $approver) {
            $this->inbox->approval($approver, $action);
        }

        return ToolResult::json([
            'action_id' => $action->id,
            'status' => 'pending_approval',
            'note' => "Aksiyon onaya gönderildi (id: {$action->id}). Bekleme; işini bitir ve özetini yaz.",
        ])->withMeta(['action_id' => $action->id]);
    }

    /** Onay: bir kez çalışır. Aynı anda iki onay tıklaması iki kez göndermez. */
    public function approve(AgentAction $action, User $user): AgentAction
    {
        $claimed = AgentAction::query()->whereKey($action->id)->where('status', AgentAction::PENDING)
            ->update(['status' => AgentAction::APPROVED, 'decided_by' => $user->id, 'decided_at' => now()]);

        $action->refresh();
        if ($claimed === 0) {
            return $action;
        }

        Audit::record('agent.action.approve', $action, meta: ['type' => $action->type], system: config("agents.tools.{$action->type}.system"), user: $user);
        $this->execute($action);
        $this->inbox->close(Inbox::SUBJECT_ACTION, $action->id);
        app(AgentRunner::class)->settle($action->run);

        return $action->refresh();
    }

    public function reject(AgentAction $action, User $user, ?string $reason = null): AgentAction
    {
        $claimed = AgentAction::query()->whereKey($action->id)->where('status', AgentAction::PENDING)
            ->update(['status' => AgentAction::REJECTED, 'decided_by' => $user->id, 'decided_at' => now(), 'result' => ['reason' => $reason]]);

        $action->refresh();
        if ($claimed === 0) {
            return $action;
        }

        Audit::record('agent.action.reject', $action, AuditLog::RESULT_BLOCKED, ['type' => $action->type, 'reason' => $reason], config("agents.tools.{$action->type}.system"), user: $user);
        $action->run->addNote("{$user->name} aksiyonu reddetti".($reason ? ": {$reason}" : '.'));
        $this->inbox->close(Inbox::SUBJECT_ACTION, $action->id);
        app(AgentRunner::class)->settle($action->run);

        return $action;
    }

    /**
     * Taslağı düzenle: konu ve gövde; alıcılar sadece izinli alıcılar arasından (pilotta değişmez).
     *
     * @param  array<string, mixed>  $changes
     */
    public function editDraft(AgentAction $action, array $changes): AgentAction|string
    {
        if ($action->status !== AgentAction::PENDING) {
            return 'Sadece bekleyen bir aksiyon düzenlenebilir.';
        }

        $payload = $action->payload;
        $agent = $action->run->agent;

        if (array_key_exists('to', $changes)) {
            $to = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) $changes['to'])));
            $allowedList = array_map('mb_strtolower', $agent->toolConstraints($action->type)['recipients'] ?? []);
            $invalid = array_filter($to, fn (string $email) => ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! self::isInternal($email) || ($allowedList !== [] && ! in_array($email, $allowedList, true)));

            if ($agent->isPilot() && $to !== ($payload['to'] ?? [])) {
                return 'Pilot agent sadece sahibine gönderebilir.';
            }
            if ($to === [] || $invalid !== []) {
                return 'Alıcılar sadece izinli alıcılar arasından seçilebilir'.($invalid ? ': '.implode(', ', $invalid) : '.');
            }
            $payload['to'] = $to;
        }

        foreach (['subject', 'body_markdown', 'text_markdown', 'message_markdown'] as $field) {
            if (isset($changes[$field]) && array_key_exists($field, $payload)) {
                $payload[$field] = mb_substr((string) $changes[$field], 0, 50000);
            }
        }

        $action->update(['payload' => $payload]);

        return $action;
    }

    /** Aksiyonu bir kez çalıştırır ve sonucu yazar. */
    public function execute(AgentAction $action): void
    {
        $p = $action->payload;

        try {
            $result = match ($action->type) {
                'send_email' => $this->sendEmail($p['to'], $p['cc'] ?? [], $p['subject'], $p['body_markdown'], $p['attach_report_id'] ?? null),
                'post_teams' => ($p['deliver_as_email'] ?? false)
                    ? $this->sendEmail($p['to'], [], 'Teams mesajı (pilot): '.$p['channel'], $p['text_markdown'], null)
                    : $this->postTeams($p['channel'], $p['text_markdown']),
                'deliver_report' => $this->sendEmail($p['to'], [], $p['subject'] ?? 'Rapor', $p['message_markdown'] ?? 'Rapor ektedir.', $p['report_id']),
            };
            $action->update(['status' => AgentAction::EXECUTED, 'executed_at' => now(), 'result' => $result]);
            $this->audit($action->run, 'agent.action.execute', $action, AuditLog::RESULT_ALLOWED);
        } catch (Throwable $e) {
            report($e);
            $action->update(['status' => AgentAction::FAILED, 'result' => ['error' => mb_substr($e->getMessage(), 0, 500)]]);
            $this->audit($action->run, 'agent.action.execute', $action, AuditLog::RESULT_FAILED);
        }
    }

    /** Onaylayabilen kullanıcılar: politika (owner ve kullanıcı id'leri). */
    public function approvers(AgentSession|AgentRun $context): array
    {
        $run = $context instanceof AgentSession ? $context->run : $context;
        $agent = $run->agent;
        $ids = [];

        foreach ($agent->approvalPolicy()['approvers'] ?? ['owner'] as $approver) {
            $ids[] = $approver === 'owner' ? $agent->owner_id : (is_numeric($approver) ? (int) $approver : null);
        }

        $users = User::query()->whereIn('id', array_filter($ids))->where('is_active', true)->get()->all();

        return $users !== [] ? $users : array_values(array_filter([$run->ownerUser()]));
    }

    public function canDecide(AgentAction $action, User $user): bool
    {
        return $user->isAdmin() || in_array($user->id, array_map(fn (User $u) => $u->id, $this->approvers($action->run)), true);
    }

    public static function isInternal(string $email): bool
    {
        $domain = mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && in_array($domain, array_map('mb_strtolower', app(AppSettings::class)->recipientDomains()), true);
    }

    /**
     * Politika (6.2): test ve pilotta her zaman; şirket dışı her zaman; always / internal_only_auto / never (sadece canlıda).
     *
     * @param  array<string, mixed>  $payload
     */
    private function requiresApproval(AgentSession $session, string $type, bool $hasExternal, array $payload): bool
    {
        if ($session->agent->isPilot() || $hasExternal) {
            return true;
        }

        $mode = $session->agent->approvalPolicy()[$type]['mode'] ?? 'always';

        return match ($mode) {
            'never' => ! $session->agent->isLive(),
            'internal_only_auto' => false,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|string
     */
    private function normalize(string $type, array $input, ?string $selfEmail = null): array|string
    {
        // Alıcı alanlarında (to/cc) KULLANICI_EPOSTASI = çalışmayı başlatan kullanıcı, yoksa agent sahibi. Konu ve gövdeye dokunulmaz.
        $token = mb_strtolower(AgentDrafts::OWN_EMAIL_TOKEN);
        $emails = function (mixed $list) use ($token, $selfEmail): array {
            $list = is_string($list) ? preg_split('/[,;\s]+/', $list) : (array) $list;
            $list = array_map(fn ($e) => mb_strtolower(trim((string) $e)), $list);
            $list = array_map(fn (string $e) => $e === $token ? mb_strtolower((string) $selfEmail) : $e, $list);

            return array_values(array_unique(array_filter($list)));
        };

        if (in_array($type, ['send_email', 'deliver_report'], true)) {
            $to = $emails($input['to'] ?? []);
            $cc = $emails($input['cc'] ?? []);
            foreach ([...$to, ...$cc] as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return "Geçersiz e-posta adresi: {$email}";
                }
            }
            if ($to === []) {
                return 'En az bir alıcı (to) gerekli.';
            }
        }

        return match ($type) {
            'send_email' => ! is_string($input['subject'] ?? null) || ! is_string($input['body_markdown'] ?? null)
                ? 'subject ve body_markdown zorunlu.'
                : array_filter(['to' => $to, 'cc' => $cc, 'subject' => mb_substr($input['subject'], 0, 300), 'body_markdown' => mb_substr($input['body_markdown'], 0, 50000), 'attach_report_id' => is_string($input['attach_report_id'] ?? null) ? $input['attach_report_id'] : null], fn ($v) => $v !== null),
            'post_teams' => ! is_string($input['channel'] ?? null) || ! is_string($input['text_markdown'] ?? null)
                ? 'channel ve text_markdown zorunlu.'
                : ['channel' => trim($input['channel']), 'text_markdown' => mb_substr($input['text_markdown'], 0, 20000)],
            'deliver_report' => ! is_string($input['report_id'] ?? null) || Report::find($input['report_id'])?->isReady() !== true
                ? 'report_id hazır bir rapor olmalı (önce create_report).'
                : ['report_id' => $input['report_id'], 'to' => $to, 'subject' => is_string($input['subject'] ?? null) ? mb_substr($input['subject'], 0, 300) : 'Rapor', 'message_markdown' => is_string($input['message_markdown'] ?? null) ? mb_substr($input['message_markdown'], 0, 20000) : 'Rapor ektedir.'],
            default => "Bilinmeyen yazma aracı: {$type}",
        };
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @return array<string, mixed>
     */
    private function sendEmail(array $to, array $cc, string $subject, string $body, ?string $reportId): array
    {
        $files = [];
        if ($reportId !== null) {
            $report = Report::query()->findOrFail($reportId);
            $files[] = ['disk' => config('reports.disk'), 'path' => $report->file_path, 'name' => $report->downloadName()];
        }

        $mail = Mail::to($to);
        if ($cc !== []) {
            $mail->cc($cc);
        }
        $mail->send(new AgentEmail($subject, $body, $files));

        return ['sent_to' => $to, 'cc' => $cc, 'attachments' => array_column($files, 'name')];
    }

    /** @return array<string, mixed> */
    private function postTeams(string $channel, string $text): array
    {
        Http::timeout(20)->post(config("agents.teams_channels.{$channel}"), ['text' => $text])->throw();

        return ['channel' => $channel];
    }

    private function refuse(AgentSession $session, string $type, string $message): ToolResult
    {
        Audit::record('agent.action.request', $session->run, AuditLog::RESULT_BLOCKED, ['type' => $type, 'error' => $message], config("agents.tools.{$type}.system"), actorType: AuditLog::ACTOR_AGENT, actorLabel: $session->agent->name, user: $session->run->ownerUser());

        return ToolResult::error($message);
    }

    private function audit(AgentRun $run, string $action, AgentAction $subject, string $result): void
    {
        Audit::record($action, $subject, $result, ['type' => $subject->type, 'run_id' => $run->id], config("agents.tools.{$subject->type}.system"), actorType: AuditLog::ACTOR_AGENT, actorLabel: $run->agent->name, user: $run->ownerUser());
    }
}
