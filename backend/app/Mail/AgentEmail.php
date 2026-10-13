<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Agent'ın onaylanmış e-postası (send_email, deliver_report). Gövde markdown'dan HTML'e çevrilir.
 */
class AgentEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{disk: string, path: string, name: string}>  $files
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyMarkdown,
        public readonly array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('agents.mail_from.address'), config('agents.mail_from.name')),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: Str::markdown($this->bodyMarkdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]).'<p style="color:#6b6266;font-size:12px">Bu e-posta Fortis agent\'ı tarafından hazırlandı ve onaylandı.</p>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(fn (array $f) => Attachment::fromStorageDisk($f['disk'], $f['path'])->as($f['name']), $this->files);
    }
}
