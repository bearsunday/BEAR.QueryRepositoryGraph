<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

class Digest extends ResourceObject
{
    #[DependsOn(uri: 'app://self/counter')]
    public function onGet(): static
    {
        return $this;
    }
}
