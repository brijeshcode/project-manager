<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment)
    {
        $user = auth()->user();
        // clients never see attachments; staff need access to the owning project
        abort_if($user->hasRole('client'), 403);
        abort_unless($attachment->attachable && $user->can('view', $attachment->attachable->accessProject()), 403);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);

        $response = $attachment->isInlineImage()
            ? $disk->response($attachment->path, $attachment->name)
            : $disk->download($attachment->path, $attachment->name);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
