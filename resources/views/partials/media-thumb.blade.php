{{-- One media, as a thumbnail that shows the full image on hover.

     Moderation needs to SEE what was uploaded: a path and a MIME type say
     nothing about what the file actually shows. The enlargement is pure CSS
     (group-hover), so it works on pages that load no JavaScript, and the
     thumbnail is also a link, because hovering does not exist on a touch
     screen.

     $media: the Media model. $size: 'sm' in a table row, 'md' in an album. --}}
@php($large = ($size ?? 'sm') === 'md')

<div class="group relative {{ $large ? 'block' : 'inline-block' }}">
    <a href="{{ $media->url() }}" target="_blank" rel="noopener noreferrer nofollow"
       class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
        {{-- Sandboxed by construction: the file was re-encoded at intake and
             SVG is refused, so no image here can carry a script (D7). --}}
        <img src="{{ $media->url() }}"
             alt="{{ $media->alt ?? '' }}"
             loading="lazy"
             class="{{ $large ? 'h-28 w-full' : 'h-14 w-20' }} object-cover transition group-hover:opacity-80">
    </a>

    <div class="pointer-events-none absolute top-full left-0 z-50 mt-2 hidden w-max max-w-sm rounded-xl border border-slate-200 bg-white p-2 shadow-xl group-hover:block dark:border-slate-700 dark:bg-slate-900">
        <img src="{{ $media->url() }}"
             alt=""
             class="max-h-96 max-w-full rounded-lg object-contain">

        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
            {{ $media->width }}x{{ $media->height }} - {{ $media->mime }}
            @if ($media->alt)
                <span class="mt-1 block text-slate-700 dark:text-slate-200">{{ $media->alt }}</span>
            @else
                <span class="mt-1 block italic">{{ __('sans texte de remplacement') }}</span>
            @endif
        </p>
    </div>
</div>
