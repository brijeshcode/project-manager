{{-- Needs a parent x-data with `ask` (null or {method, id, title, text, button}); confirm calls $wire[method](id). --}}
<div x-show="ask" x-cloak x-effect="ask && $nextTick(() => $el.querySelector('.btn-secondary')?.focus())" x-on:keydown.escape.window="ask = null" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="confirm-title" data-test="confirm-modal">
    <div class="flex min-h-screen items-center justify-center px-4 py-8">
        <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80" x-on:click="ask = null"></div>
        <div class="card relative w-full overflow-hidden px-4 pb-4 pt-5 text-left shadow-xl sm:max-w-lg sm:p-6">
            <div class="sm:flex sm:items-start">
                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/20 sm:mx-0 sm:h-10 sm:w-10">
                    <x-nav.icon name="alert" class="h-6 w-6 text-red-600 dark:text-red-400" />
                </div>
                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                    <h3 id="confirm-title" class="text-lg font-medium leading-6 text-gray-900 dark:text-white" x-text="ask && ask.title"></h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400" x-text="ask && ask.text"></p>
                </div>
            </div>
            <div class="mt-5 flex flex-col-reverse gap-3 sm:mt-4 sm:flex-row-reverse">
                <button type="button" class="btn btn-danger justify-center" x-on:click="$wire[ask.method](ask.id); ask = null" x-text="ask && (ask.button || 'Delete')" data-test="confirm-ok"></button>
                <button type="button" class="btn btn-secondary justify-center" x-on:click="ask = null">Cancel</button>
            </div>
        </div>
    </div>
</div>
