<?php

namespace App\Livewire\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Livewire\WithFileUploads;

/** Bills and receipts on expenses and expense payments, saved as Attachments (same checks as HandlesMarkdownUploads). */
trait StoresExpenseFiles
{
    use WithFileUploads;

    /** Validation rules for a multi-file property. */
    protected function fileRules(string $field): array
    {
        return [$field => 'array|max:10', "$field.*" => ['file', 'max:10240', function ($attr, $file, $fail) {
            $ext = fn ($n) => strtolower(pathinfo((string) $n, PATHINFO_EXTENSION));
            $blocked = in_array($ext($file->getClientOriginalName()), Attachment::BLOCKED_EXTENSIONS, true)
                || in_array($file->extension(), Attachment::BLOCKED_EXTENSIONS, true)
                || preg_match('~(x-msdownload|x-httpd-php|x-sh|x-executable|javascript|html|svg)~', (string) $file->getMimeType());
            $blocked && $fail('This file type is not allowed.');
        }]];
    }

    protected function storeFiles(Model $owner, array $files): void
    {
        foreach ($files as $f) {
            $owner->attachments()->create([
                'user_id' => auth()->id(), 'path' => $f->store('attachments/'.now()->format('Y/m'), 'local'),
                'name' => str_replace(['[', ']', '(', ')'], '_', $f->getClientOriginalName()), 'mime' => (string) $f->getMimeType(), 'size' => $f->getSize(),
            ]);
        }
    }
}
