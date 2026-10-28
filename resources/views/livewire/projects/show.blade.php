<div>
    <h1>{{ $project->name }}</h1>
    <p>Client: {{ $project->client->name }}</p>
    <p>Currency: {{ $project->currency() }}</p>
    <p>Status: {{ $project->status }}</p>
    <ul>
        @foreach($project->members as $member)
            <li>{{ $member->name }}</li>
        @endforeach
    </ul>
</div>
