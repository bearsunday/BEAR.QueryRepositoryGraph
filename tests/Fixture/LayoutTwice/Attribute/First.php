<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutTwice\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class First extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = ['header' => 'page://self/header'];
}
