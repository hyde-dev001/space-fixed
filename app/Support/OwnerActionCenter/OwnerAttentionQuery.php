<?php

declare(strict_types=1);

namespace App\Support\OwnerActionCenter;

use InvalidArgumentException;

final readonly class OwnerAttentionQuery
{
    public const BUCKETS = ['needs_my_decision', 'urgent_exceptions', 'waiting_on_others'];

    public const COVERAGES = [
        'all',
        'refunds',
        'prices',
        'payslips',
        'salary_changes',
        'purchase_requests',
        'suspensions',
        'terminations',
        'rehires',
        'expenses',
        'repair_rejections',
        'compliance',
        'logistics',
    ];

    public const COVERAGES_BY_BUCKET = [
        'needs_my_decision' => [
            'all',
            'refunds',
            'prices',
            'payslips',
            'salary_changes',
            'purchase_requests',
            'suspensions',
            'terminations',
            'rehires',
            'expenses',
            'repair_rejections',
        ],
        'urgent_exceptions' => ['all', 'compliance', 'refunds', 'logistics'],
        'waiting_on_others' => ['all', 'compliance', 'refunds', 'logistics'],
    ];

    public const MAX_PAGE = 100;

    public const MAX_PER_PAGE = 50;

    public const MAX_CANDIDATE_LIMIT = 5000;

    public int $candidateLimit;

    public function __construct(
        public string $bucket = 'needs_my_decision',
        public string $coverage = 'all',
        public int $page = 1,
        public int $perPage = 20,
        ?int $candidateLimit = null,
    ) {
        if (! in_array($bucket, self::BUCKETS, true)) {
            throw new InvalidArgumentException('Owner Action Center bucket is not supported.');
        }

        if (! in_array($coverage, self::COVERAGES_BY_BUCKET[$bucket], true)) {
            throw new InvalidArgumentException('Owner Action Center coverage is not supported.');
        }

        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('Owner Action Center page is out of bounds.');
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('Owner Action Center per-page value is out of bounds.');
        }

        $candidateLimit ??= $page * $perPage;
        if ($candidateLimit < 1 || $candidateLimit > self::MAX_CANDIDATE_LIMIT) {
            throw new InvalidArgumentException('Owner Action Center candidate limit is out of bounds.');
        }

        $this->candidateLimit = $candidateLimit;
    }

    public function withCandidateLimit(int $candidateLimit): self
    {
        return new self(
            bucket: $this->bucket,
            coverage: $this->coverage,
            page: $this->page,
            perPage: $this->perPage,
            candidateLimit: $candidateLimit,
        );
    }
}
