{{-- Needs a parent x-data with `del` (null or {id, name}); confirm calls Livewire delete(id). --}}
<div x-show="del" x-cloak x-on:keydown.escape.window="del = null" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-title">
    <div class="flex min-h-screen items-center justify-center px-4 py-8">
        <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80" x-on:click="del = null"></div>
        <div class="relative w-full overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl dark:bg-gray-800 sm:max-w-lg sm:p-6">
            <div class="sm:flex sm:items-start">
                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/20 sm:mx-0 sm:h-10 sm:w-10">
                    <x-nav.icon name="alert" class="h-6 w-6 text-red-600 dark:text-red-400" />
                </div>
                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                    <h3 id="delete-title" class="text-lg font-medium leading-6 text-gray-900 dark:text-white">Delete Client</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Are you sure you want to delete "<span x-text="del && del.name"></span>"? This action cannot be undone.
                        Clients with projects, invoices or payments cannot be deleted; archive them instead.
                    </p>
                </div>
            </div>
            <div class="mt-5 flex flex-col-reverse gap-3 sm:mt-4 sm:flex-row-reverse">
                <button type="button" class="btn btn-danger justify-center" x-on:click="$wire.delete(del.id); del = null">Delete</button>
                <button type="button" class="btn btn-secondary justify-center" x-on:click="del = null">Cancel</button>
            </div>
        </div>
    </div>
</div>
