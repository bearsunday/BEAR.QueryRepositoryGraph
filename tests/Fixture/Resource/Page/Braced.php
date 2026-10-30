<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Resource\Page {
    use BEAR\Resource\ResourceObject;

    class Braced extends ResourceObject
    {
        public function onGet(): static
        {
            return $this;
        }
    }
}
