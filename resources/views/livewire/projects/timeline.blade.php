<div>
    {{-- Checklist progress bar --}}
    <div>
        <div class="flex justify-between text-sm mb-1">
            <span>Checklist progress</span>
            <span>{{ number_format($checklistProgress, 1) }}%</span>
        </div>
        <div class="w-full bg-gray-200 rounded h-2">
            <div class="bg-blue-500 h-2 rounded" style="width: {{ min($checklistProgress, 100) }}%"></div>
        </div>
    </div>

    {{-- Time bar (logged vs estimated hours) --}}
    <div class="mt-4">
        <div class="flex justify-between text-sm mb-1">
            <span>Time logged</span>
            <span>{{ number_format($logged, 1) }}h / {{ number_format($estimated, 1) }}h</span>
        </div>
        <div class="w-full bg-gray-200 rounded h-2">
            <div class="bg-green-500 h-2 rounded" style="width: {{ min($timeBar * 100, 100) }}%"></div>
        </div>
    </div>
</div>
