<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Camel\Resource\App;

use BEAR\Resource\ResourceObject;

class GalleryArticle extends ResourceObject
{
    public function onGet(int $id = 0): static
    {
        return $this;
    }
}
