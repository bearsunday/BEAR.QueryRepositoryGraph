<?php

namespace BEAR\QueryRepositoryGraph\Fixture\HtmlWarnings\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class AdWriter extends ResourceObject
{
    #[Purge(uri: 'app://ads/campaign')]
    public function onPut(): static
    {
        return $this;
    }
}
