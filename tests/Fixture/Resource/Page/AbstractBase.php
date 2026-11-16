<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Resource\Page;

use BEAR\Resource\ResourceObject;

abstract class AbstractBase extends ResourceObject
{
    public function kind(): string
    {
        return ResourceObject::class;
    }
}
