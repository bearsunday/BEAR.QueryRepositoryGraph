<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\Resource\ResourceObject;

class Post extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }
}
