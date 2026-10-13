<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aylık AI bütçesinin %80'i aşıldı uyarısı (ayda bir kez).
 */
class BudgetAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly float $spent, public readonly float $limit, public readonly string $month) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Fortis: AI bütçesinin %80'i kullanıldı ({$this->month})");
    }

    public function content(): Content
    {
        $ratio = (int) round($this->spent / $this->limit * 100);

        return new Content(htmlString: sprintf(
            '<p>%s döneminde AI kullanımı $%s oldu; aylık bütçe $%s (%%%d).</p><p>Bütçe dolunca yeni sohbet AI istekleri durur. Limiti "Kullanım ve maliyet" ekranından değiştirebilirsiniz.</p>',
            e($this->month), number_format($this->spent, 2), number_format($this->limit, 2), $ratio,
        ));
    }
}
