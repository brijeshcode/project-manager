<?php

namespace App\Support;

use Illuminate\Support\Str;

class Markdown
{
    private const TASK_LINE = '/^((?:\s*>)*\s*(?:[-*+]|\d+[.)])\s+\[)([ xX])(\])/';

    /**
     * GitHub-flavoured markdown with raw HTML stripped and javascript:/data: links dropped.
     * `$interactive` turns the `- [ ]` task-list checkboxes into clickable inputs numbered `data-md-task="N"`
     * (N = nth task item in the source, the same counting toggleTask() uses).
     */
    public static function render(?string $text, bool $interactive = false): string
    {
        $html = Str::markdown((string) $text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        if (! $interactive) {
            return $html;
        }
        $n = 0;

        return preg_replace_callback('/<input (checked="" )?disabled="" type="checkbox">/', function ($m) use (&$n) {
            return '<input type="checkbox" '.(($m[1] ?? '') !== '' ? 'checked ' : '').'data-md-task="'.$n++.'" class="cursor-pointer">';
        }, $html);
    }

    /**
     * Flip the nth task item of the markdown source to `$checked`. Returns the new source, or null when the item
     * is gone or no longer in the state the user saw (someone edited meanwhile) so the caller can ignore the click.
     * ponytail: fenced code is skipped but indented code blocks are not; a `- [ ]` inside one would shift the count.
     */
    public static function toggleTask(string $md, int $index, bool $checked): ?string
    {
        $lines = explode("\n", $md);
        $fence = false;
        $seen = -1;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line)) {
                $fence = ! $fence;

                continue;
            }
            if ($fence || ! preg_match(self::TASK_LINE, $line, $m) || ++$seen !== $index) {
                continue;
            }
            if (($m[2] !== ' ') === $checked) {
                return null; // already in the requested state: stale click
            }
            $lines[$i] = preg_replace(self::TASK_LINE, '${1}'.($checked ? 'x' : ' ').'${3}', $line, 1);

            return implode("\n", $lines);
        }

        return null;
    }
}
