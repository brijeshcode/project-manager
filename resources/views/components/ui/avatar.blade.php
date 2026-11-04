{{-- Initials avatar with an instant tooltip (full name; also on keyboard focus). $user, $size (sm 24px | xs 20px | md 32px), $ring. --}}
@props(['user', 'size' => 'sm', 'ring' => 'ring-2 ring-white dark:ring-gray-800'])
@php
    $initials = collect(explode(' ', trim($user->name)))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    $dim = ['xs' => 'h-5 w-5 text-[9px]', 'sm' => 'h-6 w-6 text-[10px]', 'md' => 'h-8 w-8 text-xs'][$size] ?? 'h-6 w-6 text-[10px]';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex $dim shrink-0 items-center justify-center rounded-full bg-brand-50 font-semibold text-brand-700 $ring dark:bg-blue-900/50 dark:text-blue-200"]) }} data-tip="{{ $user->name }}" aria-label="{{ $user->name }}" role="img" tabindex="0" data-test="avatar">{{ $initials }}</span>
