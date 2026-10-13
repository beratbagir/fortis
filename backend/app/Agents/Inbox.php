<?php

namespace App\Agents;

use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\Connector;
use App\Models\Execution;
use App\Models\InboxItem;
use App\Models\User;

/**
 * Inbox öğelerini oluşturur ve kapatır. Konu (subject) kapanınca ilgili öğeler "tamamlandı" olur.
 */
class Inbox
{
    public const SUBJECT_ACTION = 'agent_action';

    public const SUBJECT_RUN = 'agent_run';

    public const SUBJECT_EXECUTION = 'execution';

    public const SUBJECT_TRIGGER = 'agent_trigger';

    public const SUBJECT_CONNECTOR = 'connector';

    public function approval(User $user, AgentAction $action): InboxItem
    {
        $run = $action->run;
        $agent = $run->agent;

        return InboxItem::create([
            'user_id' => $user->id,
            'type' => InboxItem::APPROVAL,
            'title' => self::actionTitle($action),
            'body' => $action->reason,
            'subject_type' => self::SUBJECT_ACTION,
            'subject_id' => $action->id,
            'source_label' => self::sourceLabel($run),
            'icon' => $agent->icon,
        ]);
    }

    /** @param  list<array<string, mixed>>  $questions */
    public function question(User $user, AgentRun $run, array $questions): InboxItem
    {
        return InboxItem::create([
            'user_id' => $user->id,
            'type' => InboxItem::QUESTION,
            'title' => $questions[0]['question'] ?? 'Agent bir soru sordu',
            'body' => $questions[0]['context'] ?? null,
            'subject_type' => self::SUBJECT_RUN,
            'subject_id' => $run->id,
            'source_label' => self::sourceLabel($run),
            'icon' => $run->agent->icon,
        ]);
    }

    public function alert(User $user, AgentRun $run, string $title, string $body): InboxItem
    {
        return InboxItem::create([
            'user_id' => $user->id,
            'type' => InboxItem::ALERT,
            'title' => $title,
            'body' => $body,
            'subject_type' => self::SUBJECT_RUN,
            'subject_id' => $run->id,
            'source_label' => self::sourceLabel($run),
            'icon' => $run->agent->icon,
        ]);
    }

    /** Kestra'da onay bekleyen (PAUSED) akış çalışması: akış sahibine onay. */
    public function pausedExecution(Execution $execution): void
    {
        $exists = InboxItem::query()->where('subject_type', self::SUBJECT_EXECUTION)->where('subject_id', $execution->id)->whereIn('status', [InboxItem::OPEN, InboxItem::SNOOZED])->exists();
        $user = $execution->flow?->owner ?? User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();

        if ($exists || $user === null) {
            return;
        }

        InboxItem::create([
            'user_id' => $user->id,
            'type' => InboxItem::APPROVAL,
            'title' => ($execution->flow?->title ?? 'Akış').' onay bekliyor',
            'body' => 'Akış bir onay adımında durdu. Onaylarsan devam eder, reddedersen durdurulur.',
            'subject_type' => self::SUBJECT_EXECUTION,
            'subject_id' => $execution->id,
            'source_label' => ($execution->flow?->title ?? 'Akış').' · Akış',
            'icon' => 'AK',
        ]);
    }

    /** Konusu sonuçlanan öğeleri kapatır (onaylandı, reddedildi, cevaplandı, çalışma devam etti). */
    public function close(string $subjectType, int $subjectId): void
    {
        InboxItem::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('status', '!=', InboxItem::DONE)
            ->update(['status' => InboxItem::DONE, 'read_at' => now()]);
    }

    /**
     * Tetikleyici sorunu (Kestra'ya aktarılamadı vb.): agent sahibine uyarı. Aynı tetikleyici için açık uyarı varsa
     * yenisi açılmaz, mevcut uyarının metni güncellenir ve yeniden okunmamış olur.
     */
    public function triggerAlert(AgentTrigger $trigger, string $title, string $body): ?InboxItem
    {
        $agent = $trigger->agent;
        $owner = $agent->owner;
        if ($owner === null) {
            return null;
        }

        $open = InboxItem::query()->where('subject_type', self::SUBJECT_TRIGGER)->where('subject_id', $trigger->id)->where('user_id', $owner->id)->whereIn('status', [InboxItem::OPEN, InboxItem::SNOOZED])->first();
        if ($open !== null) {
            $open->update(['title' => $title, 'body' => $body, 'read_at' => null]);

            return $open;
        }

        return InboxItem::create([
            'user_id' => $owner->id,
            'type' => InboxItem::ALERT,
            'title' => $title,
            'body' => $body,
            'subject_type' => self::SUBJECT_TRIGGER,
            'subject_id' => $trigger->id,
            'source_label' => trim($agent->name.($agent->department ? ' · '.$agent->department->name : '')),
            'icon' => $agent->icon,
        ]);
    }

    /** Bağlantı şirket genelinde kapatıldı: etkilenen agentların sahibine uyarı (sahip ve bağlantı başına tek açık uyarı). */
    public function connectorAlert(User $owner, Connector $connector, string $title, string $body): InboxItem
    {
        $open = InboxItem::query()->where('subject_type', self::SUBJECT_CONNECTOR)->where('subject_id', $connector->id)->where('user_id', $owner->id)->whereIn('status', [InboxItem::OPEN, InboxItem::SNOOZED])->first();
        if ($open !== null) {
            $open->update(['title' => $title, 'body' => $body, 'read_at' => null]);

            return $open;
        }

        $source = config('connectors.sources')[$connector->key] ?? ['label' => $connector->key, 'abbr' => '??'];

        return InboxItem::create([
            'user_id' => $owner->id,
            'type' => InboxItem::ALERT,
            'title' => $title,
            'body' => $body,
            'subject_type' => self::SUBJECT_CONNECTOR,
            'subject_id' => $connector->id,
            'source_label' => 'Bağlantılar · '.$source['label'],
            'icon' => $source['abbr'],
        ]);
    }

    /** Tetikleyici yeniden eşitlenince açık uyarıları kapanır. */
    public function closeTriggerAlerts(AgentTrigger $trigger): void
    {
        $this->close(self::SUBJECT_TRIGGER, $trigger->id);
    }

    public static function sourceLabel(AgentRun $run): string
    {
        $agent = $run->agent;

        return trim($agent->name.($agent->department ? ' · '.$agent->department->name : ''));
    }

    public static function actionTitle(AgentAction $action): string
    {
        $p = $action->payload;

        return match ($action->type) {
            'send_email' => sprintf('"%s" e-postası %s adresine gönderilsin mi?', $p['subject'] ?? 'Konu yok', implode(', ', $p['to'] ?? [])),
            'post_teams' => sprintf('%s kanalına mesaj gönderilsin mi?', $p['channel'] ?? 'Teams'),
            'deliver_report' => 'Rapor dosyası gönderilsin mi?',
            default => 'Aksiyon onayı',
        };
    }
}
