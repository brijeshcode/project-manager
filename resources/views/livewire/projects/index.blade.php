<div>
    <ul>
        @foreach($projects as $project)
            <li>{{ $project->name }} ({{ $project->client->name }})</li>
        @endforeach
    </ul>
</div>
