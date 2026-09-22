<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Canonical API error codes.
 *
 * Each case maps to a stable HTTP status and a French, user-facing message.
 * The string value is the wire code exposed in the error envelope (S8 of
 * the saas-base3 socle).
 */
enum ApiErrorCode: string
{
    case INVALID_TOKEN = 'INVALID_TOKEN';
    case ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';
    case CONTRIBUTOR_REQUIRED = 'CONTRIBUTOR_REQUIRED';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case NOT_FOUND = 'NOT_FOUND';
    case RATE_LIMITED = 'RATE_LIMITED';
    case QUOTA_BUCKET_EMPTY = 'QUOTA_BUCKET_EMPTY';
    case QUEUE_CEILING_REACHED = 'QUEUE_CEILING_REACHED';
    case CONFLICT = 'CONFLICT';
    case UNSUPPORTED_MEDIA = 'UNSUPPORTED_MEDIA';
    case PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';
    case FORBIDDEN = 'FORBIDDEN';
    case INTERNAL = 'INTERNAL';

    /**
     * HTTP status code carried by this error.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::INVALID_TOKEN => 401,
            self::ACCOUNT_INACTIVE => 403,
            self::CONFLICT => 409,
            self::CONTRIBUTOR_REQUIRED => 403,
            self::FORBIDDEN => 403,
            self::VALIDATION_FAILED => 422,
            self::NOT_FOUND => 404,
            self::RATE_LIMITED => 429,
            self::QUOTA_BUCKET_EMPTY => 429,
            self::QUEUE_CEILING_REACHED => 429,
            self::UNSUPPORTED_MEDIA => 415,
            self::PAYLOAD_TOO_LARGE => 413,
            self::INTERNAL => 500,
        };
    }

    /**
     * French, user-facing message (accented, ASCII punctuation only).
     */
    public function message(): string
    {
        return match ($this) {
            self::INVALID_TOKEN => 'Jeton d\'authentification invalide ou expiré.',
            self::ACCOUNT_INACTIVE => 'Ce compte est désactivé.',
            self::CONTRIBUTOR_REQUIRED => 'Un compte contributeur vérifié est requis pour cette action.',
            self::FORBIDDEN => 'Cette action n\'est pas permise pour ce compte.',
            self::VALIDATION_FAILED => 'Les données fournies sont invalides.',
            self::CONFLICT => 'Conflit avec l\'état actuel de la ressource.',
            self::NOT_FOUND => 'Ressource introuvable.',
            self::RATE_LIMITED => 'Trop de requêtes, veuillez réessayer plus tard.',
            self::QUOTA_BUCKET_EMPTY => 'Le crédit de publication du projet est épuisé.',
            self::QUEUE_CEILING_REACHED => 'Trop d\'annonces de cet éditeur sont déjà en revue.',
            self::UNSUPPORTED_MEDIA => 'Type de média refusé : images bitmap uniquement, jamais de SVG.',
            self::PAYLOAD_TOO_LARGE => 'Fichier trop volumineux.',
            self::INTERNAL => 'Une erreur interne est survenue.',
        };
    }
}
