<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Camel\Resource\Page;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

class CamelParent extends ResourceObject
{
    #[Embed(rel: 'article', src: 'app://self/galleryArticle')]
    public function onGet(int $id = 0): static
    {
        return $this;
    }
}
