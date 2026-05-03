<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

final class JournalLineCollection extends ResourceCollection
{
    public $collects = JournalLineResource::class;
}
