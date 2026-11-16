<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

class Liker extends ResourceObject
{
    #[Purge(uri: 'app://self/counter')]
    #[Purge(uri: 'app://self/removed')]
    #[Refresh(uri: 'page://self/index')]
    public function onPost(): static
    {
        return $this;
    }
}
