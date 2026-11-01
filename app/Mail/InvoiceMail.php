<?php

namespace App\Mail;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $this->invoice->loadMissing('client');

        return new Envelope(
            to: [new Address($this->invoice->client->contact_email)],
            subject: "Invoice {$this->invoice->number} from ".config('billing.company_name'),
        );
    }

    public function content(): Content
    {
        $this->invoice->loadMissing(['client', 'lines']);

        return new Content(view: 'emails.invoice', with: ['invoice' => $this->invoice]);
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => self::pdfBytes($this->invoice), "{$this->invoice->number}.pdf")->withMime('application/pdf'),
        ];
    }

    public static function pdfBytes(Invoice $invoice): string
    {
        $invoice->loadMissing(['client', 'lines']);

        return Pdf::loadView('invoices.pdf', ['invoice' => $invoice])->output();
    }
}
