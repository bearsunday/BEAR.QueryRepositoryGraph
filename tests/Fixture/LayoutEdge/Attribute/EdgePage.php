<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutEdge\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class EdgePage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = [
        'index' => 'page://self/part/',
        'dash' => 'page://self/part/a-b',
        'slash' => 'page://self/part/a/b',
    ];
}
