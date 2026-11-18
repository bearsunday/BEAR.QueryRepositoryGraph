<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutClash\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class Framed extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = ['header' => 'page://self/header'];
}
