<?php

namespace BEAR\QueryRepositoryGraph\Fixture\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable(expiry: 'short')] class InlineAttr extends ResourceObject { public function onGet(): static { return $this; } }
