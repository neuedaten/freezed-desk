<?php

namespace Neuedaten\FreezedDesk\Schema\FieldTypes;

/**
 * Like "images", for any uploaded file (PDFs and the like). Exports the
 * same shape; src is used with freezed:resource and context="media".
 */
class FilesType extends ImagesType
{
    public function name(): string
    {
        return 'files';
    }
}
