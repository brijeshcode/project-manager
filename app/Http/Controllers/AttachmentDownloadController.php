<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Resource;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment)
    {
        $user = auth()->user();
        $owner = $attachment->attachable;
        if ($owner instanceof Resource) {
            // resource files follow the resource's own viewers/editors (clients included)
            abort_unless($owner->canView($user) || $owner->canEdit($user), 403);
        } else {
            // clients never see other attachments; staff need access to the owning project
            abort_if($user->hasRole('client'), 403);
            abort_unless($owner && $user->can('view', $owner->accessProject()), 403);
        }

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);

        if ($attachment->encrypted) {
            $bytes = Crypt::decryptString($disk->get($attachment->path));
            $inline = $attachment->isInlineImage();
            $response = response($bytes, 200, [
                'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $attachment->name, preg_replace('/[^\x20-\x7E]|[%\\\\]/', '_', $attachment->name)),
                'Cache-Control' => 'private, no-store',
            ]);
        } else {
            $response = $attachment->isInlineImage()
                ? $disk->response($attachment->path, $attachment->name)
                : $disk->download($attachment->path, $attachment->name);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
