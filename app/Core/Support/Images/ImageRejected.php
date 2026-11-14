<?php

namespace App\Core\Support\Images;

/**
 * An image that is refused or could not be stored. The message is written for the person uploading it, in Arabic;
 * bootstrap/app.php shows it on the form field the image came from ($field), or as a 422 in JSON.
 */
final class ImageRejected extends \RuntimeException
{
    public ?string $field = null;

    /** The form field the image came from, when it is not known yet. */
    public function on(?string $field): self
    {
        $this->field ??= $field;

        return $this;
    }
}
