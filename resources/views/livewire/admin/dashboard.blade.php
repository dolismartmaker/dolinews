<div>
    <h1 style="margin:0 0 1rem">Tableau de bord</h1>

    <div class="impersonate-banner" style="display:none"></div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
            <div style="font-size:1.6rem; font-weight:700;">{{ $pendingArticles }}</div>
            <div style="color:#6b7280; font-size:0.85rem;">Articles en file de revue</div>
            <a href="{{ route('admin.review') }}">Ouvrir la file</a>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
            <div style="font-size:1.6rem; font-weight:700;">{{ $activeModerators }}</div>
            <div style="color:#6b7280; font-size:0.85rem;">Modérateurs actifs (plancher : six)</div>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
            <div style="font-size:1.6rem; font-weight:700;">{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</div>
            <div style="color:#6b7280; font-size:0.85rem;">Délai observé (médiane)</div>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
            <div style="font-size:1.6rem; font-weight:700;">{{ $activeContributors }}</div>
            <div style="color:#6b7280; font-size:0.85rem;">Contributeurs vérifiés</div>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
            <div style="font-size:1.6rem; font-weight:700;">{{ $publishedArticles }}</div>
            <div style="color:#6b7280; font-size:0.85rem;">Articles publiés</div>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
            <div style="font-size:1.6rem; font-weight:700;">{{ $projectCount }}</div>
            <div style="color:#6b7280; font-size:0.85rem;">Fiches projet</div>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:1rem;">
            <div style="font-size:1.6rem; font-weight:700;">{{ $apiCallsThisMonth }}</div>
            <div style="color:#6b7280; font-size:0.85rem;">Appels API ce mois</div>
        </div>
    </div>

    @if ($bootstrapOpen)
        <div class="flash" style="background:#eef2ff; border-color:#a5b4fc; color:#3730a3;">
            Phase d'amorçage ouverte : le super administrateur peut publier sans quorum, dans la limite de dix articles,
            jusqu'à la première soumission d'un tiers ou à la constitution de l'équipe au plancher de six.
        </div>
    @endif
</div>
