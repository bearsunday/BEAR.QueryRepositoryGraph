<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class Summary extends ResourceObject
{
    #[DependsOn(uri: 'app://self/digest')]
    public function onGet(): static
    {
        return $this;
    }
}
