<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class PostCarries extends ResourceObject
{
    public function onGet(string $articleId): static
    {
        return $this;
    }

    public function onPost(string $article_id, string $title): static
    {
        return $this;
    }
}
