<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Stats extends ResourceObject
{
    #[DependsOn(uri: 'app://self/counter')]
    #[DependsOn(uri: 'app://self/plain')]
    public function onGet(): static
    {
        return $this;
    }
}
