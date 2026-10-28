<div>
    <ul>
        @foreach($tasks as $task)
            <li>{{ $task->title }} ({{ $task->status->value }}{{ $task->is_idea ? ', idea' : '' }})</li>
        @endforeach
    </ul>
</div>
