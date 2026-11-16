<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class RankingEditor extends ResourceObject
{
    #[Purge(uri: 'app://self/ranking')]
    public function onPut(): static
    {
        return $this;
    }
}
