<?php

namespace BEAR\QueryRepositoryGraph\Fixture\LayoutMixed\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class MixedPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = ['nav' => '/layout/nav', 'own' => 'app://self/own'];
}
