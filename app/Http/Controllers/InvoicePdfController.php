<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        $owner = Gate::allows('manage-money');
        $ownClient = $user->hasRole('client') && $user->client_id !== null
            && $user->client_id === $invoice->client_id && $invoice->status !== 'void';
        abort_unless($owner || $ownClient, 404);

        return response(InvoiceMail::pdfBytes($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
