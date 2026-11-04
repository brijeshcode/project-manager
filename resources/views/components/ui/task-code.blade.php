@props(['task'])
<span {{ $attributes->merge(['class' => 'shrink-0 font-mono text-xs font-medium text-slate-400 dark:text-gray-500']) }} data-test="task-code">{{ $task->code() }}</span>
