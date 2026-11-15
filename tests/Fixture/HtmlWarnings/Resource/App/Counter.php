<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\Resource\ResourceObject;

class Counter extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
