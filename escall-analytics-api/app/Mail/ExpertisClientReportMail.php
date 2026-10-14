<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ExpertisClientReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly string $subjectLine, private readonly string $periodLabel, private readonly string $reportPath, private readonly string $filename) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.expertis-client-report', with: ['periodLabel' => $this->periodLabel]);
    }

    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('local', $this->reportPath)->as($this->filename)->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')];
    }
}
