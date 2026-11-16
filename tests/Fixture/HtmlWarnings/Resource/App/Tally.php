<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Tally extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }

    #[Purge(uri: 'app://self/counter')]
    public function onPut(): static
    {
        return $this;
    }
}
