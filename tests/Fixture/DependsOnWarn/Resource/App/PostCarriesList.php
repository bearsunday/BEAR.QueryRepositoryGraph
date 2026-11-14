<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class PostCarriesList extends ResourceObject
{
    #[DependsOn(uri: 'app://self/post-carries?article_id={id}')]
    public function onGet(string $id): static
    {
        return $this;
    }
}
