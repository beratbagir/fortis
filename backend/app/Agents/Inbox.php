<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\AgentTrigger;
use App\Models\Connector;
use App\Models\Execution;
use App\Models\InboxItem;
use App\Models\User;
use Carbon\CarbonImmutable;

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

    public const SUBJECT_BUDGET = 'budget';

    /** Bütçe dolduğu için atlanan zamanlanmış/olay çalışması (agent başına, Türkiye günü başına bir uyarı). */
    public const SUBJECT_BUDGET_SKIP = 'agent_budget_skip';

    public function approval(User $user, AgentAction $action): InboxItem
    {
        $run = $action->run;
        $agent = $run->agent;

        return InboxItem::create([
            'user_id' => $user->id,
            'type' => InboxItem::APPROVAL,
            'title' => self::actionTitle($action, self::localeOf($user)),
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
            'title' => $questions[0]['question'] ?? __('Agent bir soru sordu', [], self::localeOf($user)),
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
            'title' => __(':flow onay bekliyor', ['flow' => $execution->flow?->title ?? __('Akış', [], self::localeOf($user))], self::localeOf($user)),
            'body' => __('Akış bir onay adımında durdu. Onaylarsan devam eder, reddedersen durdurulur.', [], self::localeOf($user)),
            'subject_type' => self::SUBJECT_EXECUTION,
            'subject_id' => $execution->id,
            'source_label' => ($execution->flow?->title ?? __('Akış', [], self::localeOf($user))).' · '.__('Akış', [], self::localeOf($user)),
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

    /**
     * Bütçe dolduğu için tetikleyici çalışmayı atladı: agent sahibine uyarı. Aynı agent için bugün (Türkiye günü)
     * uyarı gönderildiyse yenisi açılmaz; olay tetikleyicisi gün içinde defalarca atlasa da sahip tek uyarı görür.
     */
    public function budgetSkipAlert(Agent $agent, string $reason): ?InboxItem
    {
        $owner = $agent->owner;
        if ($owner === null) {
            return null;
        }

        $today = CarbonImmutable::now('Europe/Istanbul')->startOfDay()->utc();
        $sent = InboxItem::query()->where('subject_type', self::SUBJECT_BUDGET_SKIP)->where('subject_id', $agent->id)->where('user_id', $owner->id)->where('created_at', '>=', $today)->exists();
        if ($sent) {
            return null;
        }

        return InboxItem::create([
            'user_id' => $owner->id,
            'type' => InboxItem::ALERT,
            'title' => __('Bütçe dolduğu için bugünkü çalışma atlandı', [], self::localeOf($owner)),
            'body' => __(':agent: :reason Bütçe artırılınca ya da ay başında çalışmalar kendiliğinden devam eder; bugün başka atlanan çalışma olursa ayrıca uyarı gönderilmez.', ['agent' => $agent->name, 'reason' => $reason], self::localeOf($owner)),
            'subject_type' => self::SUBJECT_BUDGET_SKIP,
            'subject_id' => $agent->id,
            'source_label' => trim($agent->name.($agent->department ? ' · '.$agent->department->name : '')),
            'icon' => $agent->icon,
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

    /** Onay başlığı; alıcının dilinde (verilmezse geçerli dil). */
    public static function actionTitle(AgentAction $action, ?string $locale = null): string
    {
        $p = $action->payload;

        return match ($action->type) {
            'send_email' => __('":subject" e-postası :to adresine gönderilsin mi?', ['subject' => $p['subject'] ?? __('Konu yok', [], $locale), 'to' => implode(', ', $p['to'] ?? [])], $locale),
            'post_teams' => __(':channel kanalına mesaj gönderilsin mi?', ['channel' => $p['channel'] ?? 'Teams'], $locale),
            'deliver_report' => __('Rapor dosyası gönderilsin mi?', [], $locale),
            default => __('Aksiyon onayı', [], $locale),
        };
    }

    /** Alıcının dili (users.locale); yoksa geçerli dil. Inbox metinleri alıcının dilinde yazılır. */
    public static function localeOf(?User $user): string
    {
        return $user !== null && in_array($user->locale, User::LOCALES, true) ? $user->locale : app()->getLocale();
    }
}
