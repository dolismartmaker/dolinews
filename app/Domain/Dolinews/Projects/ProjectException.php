<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Projects;

use RuntimeException;

/**
 * Project sheet failure: refused link, claimed external identity,
 * medium of another editor, full gallery.
 *
 * The code tells the API which refusal it is: the message is written for
 * a person and may change, the code may not.
 */
class ProjectException extends RuntimeException
{
    /** The medium was deposited under another editor than the sheet's. */
    public const FOREIGN_MEDIA = 1;

    /** The gallery already holds dolinews.projects.gallery_max images. */
    public const GALLERY_FULL = 2;
}
