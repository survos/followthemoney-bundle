<?php

declare(strict_types=1);

namespace Survos\FollowTheMoneyBundle\Review;

final class NullEvidenceLinker implements EvidenceLinker
{
    public function link(string $dataset, string $source): ?string
    {
        return null;
    }
}
