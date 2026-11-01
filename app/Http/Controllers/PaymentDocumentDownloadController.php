<?php

namespace App\Http\Controllers;

use App\Models\PaymentDocument;
use Illuminate\Support\Facades\Storage;

class PaymentDocumentDownloadController extends Controller
{
    public function __invoke(int $document)
    {
        $doc = PaymentDocument::findOrFail($document);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($doc->path), 404);

        $response = $disk->download($doc->path, $doc->original_name);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
