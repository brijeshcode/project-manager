<div>
    <h1 class="text-2xl font-bold mb-4">Your Projects</h1>

    @if($projects->isEmpty())
        <p>No projects yet.</p>
    @else
        <ul class="space-y-2">
            @foreach($projects as $project)
                <li>
                    <a href="{{ route('portal.project', $project) }}"
                       class="text-blue-600 hover:underline">
                        {{ $project->name }}
                    </a>
                    <span class="text-sm text-gray-500">({{ $project->status }})</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
