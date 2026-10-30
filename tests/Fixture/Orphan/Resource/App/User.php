<?php

namespace Orphan\Resource\App;

use BEAR\Resource\ResourceObject;

class User extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
