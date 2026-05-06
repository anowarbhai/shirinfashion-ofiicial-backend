<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactMessageReceived extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public ContactMessage $contactMessage)
    {
    }

    public function build(): self
    {
        return $this
            ->replyTo($this->contactMessage->email, $this->contactMessage->name)
            ->subject('New contact message: '.$this->contactMessage->subject)
            ->view('emails.contact-message');
    }
}
