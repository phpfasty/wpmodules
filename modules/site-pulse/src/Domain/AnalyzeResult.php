<?php

/**
 * Response of `POST /v1/analyze` narrowed to what the editor sidebar shows: score 0–100, findings grouped by
 * status, "where the points went", ruleset and a few text metrics.
 */

declare(strict_types=1);

namespace PhpFasty\Module\SitePulse\Domain;

final class AnalyzeResult
{
    private const METRICS = ['word_count', 'water', 'nausea_classic', 'nausea_academic', 'flesch_oborneva'];

    /**
     * @param list<Finding>        $findings
     * @param list<string>         $lost
     * @param array<string, float> $metrics
     */
    private function __construct(
        public readonly int $score,
        public readonly array $findings,
        public readonly array $lost,
        public readonly string $rulesetVersion,
        public readonly string $morph,
        public readonly array $metrics,
    ) {
    }

    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body): self
    {
        if (!is_int($body['score'] ?? null) && !is_float($body['score'] ?? null)) {
            throw new UpstreamException('sitepulse_bad_response', 'SitePulse returned no score.', 502);
        }
        $findings = [];
        foreach (is_array($body['findings'] ?? null) ? $body['findings'] : [] as $row) {
            $finding = Finding::fromArray($row);
            if (null !== $finding) {
                $findings[] = $finding;
            }
        }
        $lost = array_values(array_filter(is_array($body['lost'] ?? null) ? $body['lost'] : [], is_string(...)));
        $metrics = [];
        $raw = is_array($body['metrics'] ?? null) ? $body['metrics'] : [];
        foreach (self::METRICS as $key) {
            if (is_int($raw[$key] ?? null) || is_float($raw[$key] ?? null)) {
                $metrics[$key] = (float) $raw[$key];
            }
        }

        return new self(
            max(0, min(100, (int) round((float) $body['score']))),
            $findings,
            $lost,
            is_string($body['ruleset_version'] ?? null) ? $body['ruleset_version'] : '',
            is_string($body['morph'] ?? null) ? $body['morph'] : '',
            $metrics,
        );
    }

    /** @return array<string, list<Finding>> status => findings, fail first */
    public function grouped(): array
    {
        $out = array_fill_keys(Finding::STATUSES, []);
        foreach ($this->findings as $f) {
            $out[$f->status][] = $f;
        }

        return $out;
    }

    /**
     * @return array{score: int, groups: array<string, list<array{rule_id: string, category: string, severity: string,
     *               status: string, title: string, recommendation: string, spans: list<array{block_id: string, start: int,
     *               end: int}>}>>, lost: list<string>, ruleset_version: string, morph: string, metrics: array<string, float>}
     */
    public function toArray(): array
    {
        $groups = [];
        foreach ($this->grouped() as $status => $list) {
            $groups[$status] = array_map(static fn (Finding $f): array => $f->toArray(), $list);
        }

        return [
            'score' => $this->score,
            'groups' => $groups,
            'lost' => $this->lost,
            'ruleset_version' => $this->rulesetVersion,
            'morph' => $this->morph,
            'metrics' => $this->metrics,
        ];
    }
}
