<?php

namespace BEAR\QueryRepositoryGraph\Fixture\CachedView\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class Other extends ResourceObject
{
    #[Purge(uri: 'app://self/log')]
    public function onPost(): static
    {
        return $this;
    }
}
