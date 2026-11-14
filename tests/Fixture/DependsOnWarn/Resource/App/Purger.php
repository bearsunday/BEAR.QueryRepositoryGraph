<?php

namespace BEAR\QueryRepositoryGraph\Fixture\DependsOnWarn\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class Purger extends ResourceObject
{
    #[Purge(uri: 'app://self/origin{?articleId}')]
    #[Purge(uri: 'app://self/origin')]
    public function onPost(string $articleId): static
    {
        return $this;
    }
}
