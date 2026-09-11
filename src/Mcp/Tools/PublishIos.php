<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Mcp\Publishing\PublishGuide;

final class PublishIos extends PublishGuide
{
    protected function platform(): string
    {
        return 'ios';
    }
}
