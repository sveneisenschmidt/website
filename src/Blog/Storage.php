<?php

namespace Website\Blog;

use LogicException;

/**
 * The folder storage/ next to www/, for files that the web server must not
 * serve and no backup includes: the uploads of the assistant, the key of the
 * visitor statistics and, locally, the mails of bin/mail-catcher.php.
 * www/index.php and the tests set it as the Kirby root "storage".
 */
final class Storage
{
    /**
     * The path of the folder. Without the root, the method throws. The
     * visitor statistics would otherwise hash every address with an empty
     * key, and anyone could compute those fingerprints again.
     */
    public static function root(): string
    {
        return kirby()->root('storage') ?? throw new LogicException('The Kirby root "storage" is not set; www/index.php sets it.');
    }
}
