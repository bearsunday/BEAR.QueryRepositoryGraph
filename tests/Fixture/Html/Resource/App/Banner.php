<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Html\Resource\App;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

class Banner extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }

    #[Refresh(uri: 'page://self/front')]
    public function onPut(): static
    {
        return $this;
    }
}
