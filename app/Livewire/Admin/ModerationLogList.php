<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Core\Admin\Livewire\BaseListComponent;
use App\Domain\Dolinews\Models\ModerationLog;
use App\Domain\Dolinews\Moderation\ModerationException;
use App\Domain\Dolinews\Moderation\ModerationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * @return list<array{key: string, label: string, sortable: bool, searchable: bool}>
     */
    protected function columns(): array
    {
        return [
            ['key' => 'id', 'label' => 'Id', 'sortable' => true, 'searchable' => false],
            ['key' => 'action', 'label' => 'Acte', 'sortable' => true, 'searchable' => true],
            ['key' => 'rule_ref', 'label' => 'Règle', 'sortable' => false, 'searchable' => true],
            ['key' => 'motive', 'label' => 'Motif', 'sortable' => false, 'searchable' => true],
            ['key' => 'requires_confirmation', 'label' => 'A confirmer', 'sortable' => true, 'searchable' => false],
            ['key' => 'confirmed_at', 'label' => 'Confirmé le', 'sortable' => true, 'searchable' => false],
            ['key' => 'created_at', 'label' => 'Date', 'sortable' => true, 'searchable' => false],
        ];
    }

    /**
     * Awaiting confirmations stand out, action column offers the
     * confirm button (SPEC 9.6).
     */
    public function actions(): array
    {
        return [
            ['label' => 'Confirmer', 'method' => 'confirm'],
        ];
    }

    /**
     * Confirm one act as the second moderator.
     */
    public function confirm(ModerationService $moderation, int $logId): void
    {
        /** @var User|null $actor */
        $actor = auth('web')->user();

        abort_unless($actor !== null && ($actor->isModerator() || $actor->is_super_admin), 403);

        $log = ModerationLog::query()->findOrFail($logId);

        try {
            $moderation->confirm($log, $actor);
        } catch (ModerationException $e) {
            $this->addError('confirm', $e->getMessage());
        }
    }
}
