<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class Writer extends ResourceObject
{
    #[Purge(uri: 'app://self/article?id={id}')]
    public function onPost(string $id): static
    {
        return $this;
    }
}
