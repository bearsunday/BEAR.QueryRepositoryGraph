<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\Resource\ResourceObject;

class ArchiveList extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
