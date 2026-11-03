<?php

namespace App\Livewire\Concerns;

use App\Models\Attachment;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

/**
 * Backs <x-ui.markdown-editor>: pasted/dropped files arrive in $upload, storeUpload() saves them as
 * attachments of uploadTarget() and returns what the editor inserts. The host decides who may upload.
 */
trait HandlesMarkdownUploads
{
    use WithFileUploads;

    public $upload;

    /** The model (using HasAttachments) that owns the files; authorise here. */
    abstract protected function uploadTarget(): Model;

    public function storeUpload(): array
    {
        $target = $this->uploadTarget();

        try {
            $this->validate(['upload' => ['required', 'file', 'max:10240', function ($attr, $file, $fail) {
                $ext = fn ($n) => strtolower(pathinfo((string) $n, PATHINFO_EXTENSION));
                $blocked = in_array($ext($file->getClientOriginalName()), Attachment::BLOCKED_EXTENSIONS, true)
                    || in_array($file->extension(), Attachment::BLOCKED_EXTENSIONS, true)
                    || preg_match('~(x-msdownload|x-httpd-php|x-sh|x-executable|javascript|html|svg)~', (string) $file->getMimeType());
                $blocked && $fail('This file type is not allowed.');
            }]]);
        } catch (ValidationException $e) {
            $this->reset('upload');

            return ['error' => $e->validator->errors()->first('upload')];
        }

        $name = str_replace(['[', ']', '(', ')'], '_', $this->upload->getClientOriginalName());
        $mime = (string) $this->upload->getMimeType();
        $size = $this->upload->getSize();
        $path = $this->upload->store('attachments/'.now()->format('Y/m'), 'local');
        $a = $target->attachments()->create(['user_id' => auth()->id(), 'path' => $path, 'name' => $name, 'mime' => $mime, 'size' => $size]);
        $this->reset('upload');

        return ['name' => $a->name, 'url' => $a->url(), 'image' => $a->isInlineImage()];
    }

    /** Server-side render for the editor's Preview tab. */
    public function previewMarkdown(string $text): string
    {
        return Markdown::render($text);
    }
}
