<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

use RuntimeException;

/**
 * Project sheet failure: refused link or claimed external identity.
 */
class ProjectException extends RuntimeException {}
