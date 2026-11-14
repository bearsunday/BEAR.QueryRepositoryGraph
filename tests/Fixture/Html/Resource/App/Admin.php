<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Html\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class Admin extends ResourceObject
{
    #[Purge(uri: 'app://self/banner')]
    public function onPut(): static
    {
        return $this;
    }
}
