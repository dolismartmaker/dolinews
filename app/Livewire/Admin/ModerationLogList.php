<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The moderation journal (SPEC 9.4): every act, who, when, which rule,
 * which motive. Also where a second moderator confirms a
 * conflict-of-interest act within seven days (SPEC 9.6).
 */
class ModerationLogList extends BaseListComponent
{
    public string $sortField = 'id';

    public string $sortDir = 'desc';

    public function mount(): void
    {
        $this->mountAuthorizeAdmin();
    }

    /**
     * @return Builder<ModerationLog>
     */
    protected function baseQuery(): Builder
    {
        return ModerationLog::query()
            ->select('moderation_log.*')
            ->with(['moderator', 'confirmedBy']);
    }

    public function heading(): string
    {
        return __('Journal de modération');
    }

    public function intro(): string
    {
        return __('Tout acte de modération, avec la règle invoquée et le motif. Un acte pris en conflit d\'intérêts est annulé d\'office s\'il n\'est pas confirmé sous sept jours.');
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => __('Id'), 'sortable' => true, 'searchable' => false],
            ['key' => 'action', 'label' => __('Acte'), 'sortable' => true, 'searchable' => true],
            ['key' => 'rule_ref', 'label' => __('Règle'), 'sortable' => false, 'searchable' => true],
            ['key' => 'motive', 'label' => __('Motif'), 'sortable' => false, 'searchable' => true],
            ['key' => 'requires_confirmation', 'label' => __('À confirmer'), 'sortable' => true, 'searchable' => false],
            ['key' => 'confirmed_at', 'label' => __('Confirmé le'), 'sortable' => true, 'searchable' => false],
            ['key' => 'created_at', 'label' => __('Date'), 'sortable' => true, 'searchable' => false],
        ];
    }

    /**
     * Spell out the confirmation flag: the raw cast prints "1" on the acts that
     * are waiting and nothing on the others, so the column that matters most on
     * this screen was the least readable.
     */
    public function formatCell(Model $row, string $key): string
    {
        if ($key === 'requires_confirmation') {
            if (! $row->getAttribute($key)) {
                return '';
            }

            return $row->getAttribute('confirmed_at') === null ? __('en attente') : __('confirmé');
        }

        return parent::formatCell($row, $key);
    }

    /**
     * Awaiting confirmations stand out, action column offers the
     * confirm button (SPEC 9.6).
     */
    public function actions(): array
    {
        return [
            ['label' => __('Confirmer'), 'method' => 'confirm'],
        ];
    }

    /**
     * Confirm one act as the second moderator.
     *
     * The service is resolved here and not injected: Livewire calls an action
     * with the parameters of the wire:click and nothing else, so a typed first
     * parameter received the row id.
     */
    public function confirm(int $logId): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor !== null && ($actor->isModerator() || $actor->is_super_admin), 403);

        $log = ModerationLog::query()->findOrFail($logId);

        try {
            app(ModerationService::class)->confirm($log, $actor);
        } catch (ModerationException $e) {
            // The generic table has no field to hang a validation message on,
            // so the refusal travels as a toast -- and is logged, because a
            // refused confirmation is what leaves an act cancelled by default.
            Log::warning('ModerationLogList: confirmation refused', [
                'log_id' => $logId,
                'reason' => $e->getMessage(),
            ]);

            $this->dispatch('notify', message: $e->getMessage(), level: 'error');

            return;
        }

        $this->dispatch('notify', message: __('Acte confirmé.'));
    }
}
