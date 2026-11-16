<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Layout\Resource\Page\Esi;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

class Ranking extends ResourceObject
{
    #[Embed(rel: 'ranking', src: 'app://self/ranking')]
    public function onGet(): static
    {
        return $this;
    }
}
