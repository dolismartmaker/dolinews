<div>
    <h1 style="margin:0 0 0.5rem">File de revue</h1>

    <p style="color:#6b7280; font-size:0.9rem;">
        Priorité aux annonces de sécurité, puis ancienneté.
        Médiane observée : <strong>{{ $medianSeconds !== null ? round($medianSeconds / 3600).' h' : '-' }}</strong>
        - attente la plus ancienne : <strong>{{ $oldestPendingDays !== null ? $oldestPendingDays.' jours' : '-' }}</strong>
        - ces chiffres sont publics, jamais une promesse.
    </p>

    <table class="admin-table">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
                <th>Auteur</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr wire:key="row-{{ $row->getKey() }}">
                    @foreach ($columns as $column)
                        <td>{{ $this->formatCell($row, $column['key']) }}</td>
                    @endforeach
                    <td>{{ $row->author?->display_name ?? $row->author?->name ?? '-' }}</td>
                    <td class="row-actions">
                        <a class="row-action-btn" href="{{ route('admin.review.show', $row) }}">Relire</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) + 2 }}">File vide : aucune soumission en attente.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $rows->links() }}
    </div>
</div>
