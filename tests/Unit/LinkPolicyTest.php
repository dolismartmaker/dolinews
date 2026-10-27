<?php

declare(strict_types=1);

use App\Domain\Dolinews\Enums\LinkType;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Projects\LinkPolicy;

it('refuses url shorteners outright', function (string $url): void {
    $policy = new LinkPolicy;

    expect($policy->validate($url)['ok'])->toBeFalse();
})->with([
    'https://bit.ly/3xY2z',
    'https://tinyurl.com/2pzh8eu',
    'https://t.co/abc123',
]);

it('refuses non-http schemes and unreadable urls', function (): void {
    $policy = new LinkPolicy;

    expect($policy->validate('javascript:alert(1)')['ok'])->toBeFalse()
        ->and($policy->validate('not-a-url')['ok'])->toBeFalse();
});

it('accepts regular http and https urls', function (): void {
    $policy = new LinkPolicy;

    expect($policy->validate('https://www.dolistore.com/fr/modules/123-module.html')['ok'])->toBeTrue();
});

it('extracts the dolistore sheet id from product urls', function (string $url, ?string $expected): void {
    $policy = new LinkPolicy;

    expect($policy->extractExternalId(LinkType::DOLISTORE, $url))->toBe($expected);
})->with([
    ['https://www.dolistore.com/fr/modules/250124-mon-module.html', '250124'],
    ['https://dolistore.com/en/modules/47-other.html', '47'],
    ['https://www.dolistore.com/fr/other-page', null],
]);

it('extracts nothing from non-dolistore link types', function (): void {
    $policy = new LinkPolicy;

    expect($policy->extractExternalId(LinkType::SHOP, 'https://shop.example.com/250124'))->toBeNull();
});

it('signals link domains off the editor declared domain', function (): void {
    $policy = new LinkPolicy;

    // Unsaved model: pure matching logic, no database involved.
    $editor = new Editor(['website' => 'https://www.acme.example']);

    expect($policy->matchesEditorDomain('https://acme.example/pricing', $editor))->toBeTrue()
        ->and($policy->matchesEditorDomain('https://evil.example/pricing', $editor))->toBeFalse();
});

it('treats editors without a declared domain as never mismatching', function (): void {
    $policy = new LinkPolicy;

    $editor = new Editor([]);

    expect($policy->matchesEditorDomain('https://anywhere.example/x', $editor))->toBeTrue();
});

it('never flags dolistore or forge links, which live off the editor domain by nature', function (): void {
    $policy = new LinkPolicy;
    $editor = new Editor(['website' => 'https://www.acme.example']);

    expect($policy->matchesEditorDomain('https://github.com/acme/module', $editor, LinkType::REPO))->toBeTrue()
        ->and($policy->matchesEditorDomain('https://www.dolistore.com/fr/modules/1-x.html', $editor, LinkType::DOLISTORE))->toBeTrue()
        // A CI run sits on its forge, a capTests instance anywhere.
        ->and($policy->matchesEditorDomain('https://acme.github.io/module/tests/', $editor, LinkType::TESTS))->toBeTrue()
        ->and($policy->matchesEditorDomain('https://evil.example/doc', $editor, LinkType::DOC))->toBeFalse()
        ->and($policy->matchesEditorDomain('https://docs.acme.example/x', $editor, LinkType::DOC))->toBeTrue();
});

it('normalizes a host the way the domain alert compares it', function (?string $url, ?string $expected): void {
    expect(LinkPolicy::hostOf($url))->toBe($expected);
})->with([
    ['https://WWW.Acme.Example/path', 'acme.example'],
    ['https://docs.acme.example', 'docs.acme.example'],
    ['not-a-url', null],
    [null, null],
]);
