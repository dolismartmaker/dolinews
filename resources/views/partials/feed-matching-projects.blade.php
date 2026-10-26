{{-- Sheets matching the free-text search, above the feed (SPEC 6.1). --}}
@if ($matchingProjects->isNotEmpty())
    <div class="card mb-6">
        <div class="card-body">
            <h2 class="card-title">{{ __('Fiches correspondantes') }}</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($matchingProjects as $project)
                    <li>
                        <a class="link font-medium" href="{{ route('projects.show', $project->slug) }}">{{ $project->name }}</a>
                        <span class="text-slate-500 dark:text-slate-400">- {{ $project->editor->name }}</span>
                        <p class="text-slate-600 dark:text-slate-300">{{ $project->summary }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
