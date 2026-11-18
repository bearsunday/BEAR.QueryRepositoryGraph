<?php

declare(strict_types=1);

namespace QrGraphDemo\Attribute;

use Attribute;
use BEAR\Resource\Annotation\AbstractResourceLayout;

#[Attribute(Attribute::TARGET_CLASS)]
final class IndexPage extends AbstractResourceLayout
{
    /** @var array<string, string> */
    public const EMBED = [...BlogPage::EMBED, 'pickup' => 'page://self/layout/pickup'];
}
