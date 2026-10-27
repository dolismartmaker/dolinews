{{-- The test attestations of a sheet or of one announcement (SPEC 10),
     rendered the same whatever their source: a capTests instance and a
     CI run say the same kind of thing, and the service runs neither.
     A report that no longer answers keeps its row and says so: the
     reader judges an indicator whose detail is gone.

     A stacked list rather than a table: the box lives in the side
     column, where four columns pushed the report link out of sight. --}}
@if ($attestations->isNotEmpty())
    <div class="card">
        <div class="card-body">
            {{-- Never "certifié", never "qualité": the instances are
                 hosted by the editors themselves, so the indicator
                 stays declarative (SPEC 10). --}}
            <h2 class="card-title">{{ __('Tests publiés par l\'éditeur') }}</h2>
            <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                @foreach ($attestations as $attestation)
                    <li class="py-2">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="min-w-0 text-slate-600 dark:text-slate-300">{{ $attestation->metric }}</span>
                            <span class="shrink-0 font-semibold text-slate-900 dark:text-white">{{ $attestation->value }}{{ $attestation->unit ? ' '.$attestation->unit : '' }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                            <span>{{ app(\App\Domain\Dolinews\Attestations\AttestationService::class)->freshness($attestation) }}</span>
                            {{-- Outgoing links: nofollow ugc, no exception (D8). --}}
                            <a class="link" href="{{ $attestation->source_url }}" rel="nofollow ugc">{{ __('Rapport') }}</a>
                            @if ($attestation->is_broken)
                                <span class="badge badge-warning">{{ __('lien cassé') }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
