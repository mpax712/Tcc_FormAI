<?php

namespace App\Application\DTOs;

final readonly class GradingRequest
{
    public function __construct(
        public string $question,
        public string $expectedAnswer,
        public array $rubric,
        public string $studentAnswer,
        public string $teacherInstruction,
        public float $maximumScore,
        public int $promptVersion,
        public string $locale,
        public string $idempotencyKey,
        public string $safetyIdentifier,
    ) {}
}
