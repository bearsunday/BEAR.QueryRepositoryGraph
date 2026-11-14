<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class PostCamel extends ResourceObject
{
    public function onGet(string $article_id): static
    {
        return $this;
    }

    public function onPost(string $articleId, string $title): static
    {
        return $this;
    }
}
