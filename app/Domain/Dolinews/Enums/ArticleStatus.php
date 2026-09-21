<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Where an article stands in the a-priori review circuit (SPEC 4.3/5.1).
 *
 * draft -> pending -> published | rejected -> pending (resubmission).
 * hidden: masked by moderation on a published article.
 */
enum ArticleStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case PUBLISHED = 'published';
    case REJECTED = 'rejected';
    case HIDDEN = 'hidden';

    /**
     * Human-readable status, for the back-office lists and the author's own
     * screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('brouillon'),
            self::PENDING => __('en revue'),
            self::PUBLISHED => __('publié'),
            self::REJECTED => __('refusé'),
            self::HIDDEN => __('masqué'),
        };
    }
}
