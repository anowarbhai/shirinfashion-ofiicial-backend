<?php

namespace App\Mail;

use App\Models\DatabaseBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DatabaseBackupReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly DatabaseBackup $backup,
        public readonly string $downloadUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Database backup ready - Shirin Fashion',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.database-backup-ready',
        );
    }
}
