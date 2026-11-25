<?php
declare(strict_types=1);

/** Validate an uploaded MP4 by its ISO Base Media File Format signature. */
function nbc_is_mp4_file(string $path): bool
{
    if (!is_file($path) || filesize($path) < 12) {
        return false;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 12);
    fclose($handle);

    return is_string($header) && strlen($header) === 12 && substr($header, 4, 4) === 'ftyp';
}
