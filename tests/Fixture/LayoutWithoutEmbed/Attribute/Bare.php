<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutWithoutEmbed\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class Bare extends AbstractResourceLayout
{
}
