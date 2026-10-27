<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Enums;

/**
 * Typed links of a project sheet (SPEC 4.2).
 */
enum LinkType: string
{
    case DOLISTORE = 'dolistore';
    case SHOP = 'shop';
    case DEMO = 'demo';
    case DOC = 'doc';
    case REPO = 'repo';
    case TESTS = 'tests';
    case SUPPORT = 'support';
    case OTHER = 'other';

    /**
     * Label shown to readers, where the stored value is a code.
     */
    public function label(): string
    {
        return match ($this) {
            self::DOLISTORE => __('Fiche Dolistore'),
            self::SHOP => __('Boutique de l\'éditeur'),
            self::DEMO => __('Démonstration'),
            self::DOC => __('Documentation'),
            self::REPO => __('Code source'),
            self::TESTS => __('Rapports de tests'),
            self::SUPPORT => __('Support'),
            self::OTHER => __('Autre lien'),
        };
    }

    /**
     * Whether a link of this type is expected on the editor's own domain
     * (SPEC 8). Dolistore and the forges host everyone: a link there is
     * off the editor's domain by nature, and flagging it would bury the
     * mismatches that mean something. Test reports live on the same
     * hosts: a CI run sits on its forge, a capTests instance wherever
     * the editor runs it.
     */
    public function expectsEditorDomain(): bool
    {
        return ! in_array($this, [self::DOLISTORE, self::REPO, self::TESTS], true);
    }

    /**
     * The types compared against the editor's domain, as stored values.
     *
     * @return list<string>
     */
    public static function editorDomainValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->expectsEditorDomain()),
        ));
    }
}
