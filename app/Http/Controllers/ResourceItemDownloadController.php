<?php

namespace App\Http\Controllers;

use App\Models\ResourceItem;
use Illuminate\Support\Facades\Storage;

class ResourceItemDownloadController extends Controller
{
    public function __invoke(int $item)
    {
        $item = ResourceItem::visibleTo(auth()->user())->whereIn('type', ['image', 'file'])->findOrFail($item);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($item->value), 404);

        return $item->type === 'image'
            ? $disk->response($item->value, $item->original_name)
            : $disk->download($item->value, $item->original_name);
    }
}
