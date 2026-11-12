<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOn\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Entries extends ResourceObject
{
    #[DependsOn(uri: 'app://self/article?id={articleId}')]
    #[DependsOn(uri: 'app://self/author{?author_id}')]
    public function onGet(string $articleId, string $author_id): static
    {
        return $this;
    }
}
