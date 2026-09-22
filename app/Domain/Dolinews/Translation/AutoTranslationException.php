<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Translation;

use RuntimeException;

/**
 * A translation asked for explicitly that could not be produced
 * (SPEC 5.7).
 *
 * Carries a message meant to be read by the editor who clicked: a spent
 * allowance, a language already there and an engine that answered
 * nothing call for three different things, and "an error occurred"
 * tells them apart for nobody.
 */
class AutoTranslationException extends RuntimeException {}
