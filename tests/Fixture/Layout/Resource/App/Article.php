<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\App;

use BEAR\Resource\ResourceObject;

class Article extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }
}
