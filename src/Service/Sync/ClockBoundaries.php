<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use JWadhams\JsonLogic;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects the moments at which a page render can change only because time
 * passes (live-sync contract C6 `valid_until`, decision F06).
 *
 * A page render opens a scope with {@see begin()}; conditions and texts that
 * use the clock report to it; {@see end()} returns the earliest moment (a Unix
 * timestamp) or null when the result does not depend on the clock. A scope that
 * ends hands its moment to the scope around it, so a cached part of a page
 * (its sections) carries its own moment and the page that reuses it still sees
 * it ({@see note()}). Outside a scope every report is dropped, so actions, jobs
 * and commands that evaluate conditions cost nothing.
 *
 * What counts as clock use (the system variables `current_date`,
 * `current_datetime`, `current_time`):
 *  - a comparison of the clock with a moment, fixed or read from a variable of
 *    the user (`last_login`): the result flips at that moment (and one unit
 *    later for `<=`/`==`/`!=`), so the next such moment is the boundary;
 *  - a text that shows the clock (`{{system.current_time}}`): the next change of
 *    what it shows, but never less than {@see MIN_TEXT_SECONDS} ahead;
 *  - any other use the server cannot bound: now + {@see UNBOUNDED_SECONDS}.
 *
 * Moments are read in the PHP default timezone, the same one the system
 * variables are formatted with, so a boundary and the value it guards agree.
 */
final class ClockBoundaries implements ResetInterface
{
    public const UNBOUNDED_SECONDS = 300;
    public const MIN_TEXT_SECONDS = 300;

    private const CLOCK_VARIABLES = ['current_date', 'current_datetime', 'current_time'];
    private const COMPARISONS = ['>', '>=', '<', '<=', '==', '===', '!=', '!=='];

    /** @var list<int|null> one entry per open scope: its earliest moment */
    private array $scopes = [];

    public function reset(): void
    {
        $this->scopes = [];
    }

    public function begin(): void
    {
        $this->scopes[] = null;
    }

    /**
     * Close the innermost scope and return its earliest moment. The moment is
     * also passed to the scope around it.
     */
    public function end(): ?int
    {
        if ($this->scopes === []) {
            return null;
        }

        $until = array_pop($this->scopes);
        $this->note($until);

        return $until;
    }

    /**
     * Run a computation inside its own scope.
     *
     * @template T
     * @param callable(): T $compute
     * @return array{value: T, valid_until: int|null}
     */
    public function collect(callable $compute): array
    {
        $this->begin();
        try {
            $value = $compute();
        } catch (\Throwable $e) {
            $this->end();
            throw $e;
        }

        return ['value' => $value, 'valid_until' => $this->end()];
    }

    /** Report a moment (Unix timestamp); null is ignored. */
    public function note(?int $moment): void
    {
        if ($moment === null || $this->scopes === []) {
            return;
        }

        $last = array_key_last($this->scopes);
        $this->scopes[$last] = $this->scopes[$last] === null ? $moment : min($this->scopes[$last], $moment);
    }

    /**
     * A JSON Logic condition was evaluated with these variables.
     *
     * @param array<array-key, mixed> $logic the decoded condition
     * @param array<string, mixed> $variables what it was evaluated with (to read a user's moment)
     */
    public function noteCondition(array $logic, array $variables): void
    {
        if ($this->scopes === [] || !$this->usesClock($logic)) {
            return;
        }

        $this->visit($logic, $variables, time());
    }

    /**
     * A text is about to be interpolated.
     */
    public function noteText(string $text): void
    {
        if ($this->scopes === [] || !str_contains($text, 'current_')) {
            return;
        }
        if (preg_match_all('/\{\{\{?\s*(?:system\.)?(current_date|current_datetime|current_time)\b/', $text, $matches) < 1) {
            return;
        }

        $now = time();
        $floor = $now + self::MIN_TEXT_SECONDS;
        foreach ($matches[1] as $name) {
            $next = $name === 'current_date' ? $this->nextMidnight($now) : $floor;
            $this->note(max($next, $floor));
        }
    }

    /**
     * @param mixed $value any decoded JSON value
     */
    public function noteTexts(mixed $value): void
    {
        if ($this->scopes === []) {
            return;
        }
        if (is_string($value)) {
            $this->noteText($value);

            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->noteTexts($item);
            }
        }
    }

    private function usesClock(mixed $node): bool
    {
        if (!is_array($node)) {
            return false;
        }
        foreach ($node as $key => $value) {
            if ($key === 'var' && $this->clockName($value) !== null) {
                return true;
            }
            if ($this->usesClock($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed>|mixed $node
     * @param array<string, mixed> $variables
     */
    private function visit(mixed $node, array $variables, int $now): void
    {
        if (!is_array($node)) {
            return;
        }

        $operation = $this->operation($node);
        if ($operation === null) {
            foreach ($node as $child) {
                $this->visit($child, $variables, $now);
            }

            return;
        }

        [$op, $args] = $operation;
        if ($op === 'var') {
            // Reached only when no comparison took the clock: it is used some other way.
            if ($this->clockName($args) !== null) {
                $this->note($now + self::UNBOUNDED_SECONDS);
            }

            return;
        }

        $args = is_array($args) && array_is_list($args) ? $args : [$args];
        if (in_array($op, self::COMPARISONS, true)) {
            $this->visitComparison($op, $args, $variables, $now);

            return;
        }

        foreach ($args as $arg) {
            $this->visit($arg, $variables, $now);
        }
    }

    /**
     * @param list<mixed> $args
     * @param array<string, mixed> $variables
     */
    private function visitComparison(string $op, array $args, array $variables, int $now): void
    {
        foreach ($args as $index => $arg) {
            $clock = $this->clockOperand($arg);
            if ($clock === null) {
                $this->visit($arg, $variables, $now);
                continue;
            }

            $peers = array_values(array_filter($args, static fn (int|string $i): bool => $i !== $index, ARRAY_FILTER_USE_KEY));
            if ($peers === []) {
                $this->note($now + self::UNBOUNDED_SECONDS);
                continue;
            }
            foreach ($peers as $peer) {
                if ($this->usesClock($peer)) {
                    $this->note($now + self::UNBOUNDED_SECONDS);
                    continue;
                }
                $moment = $this->momentOf($peer, $variables);
                $boundary = $moment === null
                    ? $now + self::UNBOUNDED_SECONDS
                    : $this->nextFlip($clock, $moment, $now, count($args) === 2 ? $this->clockOnLeft($op, $index) : null);
                $this->note($boundary);
            }
        }
    }

    /** The clock variable an operand reads, or null. */
    private function clockOperand(mixed $operand): ?string
    {
        $operation = is_array($operand) ? $this->operation($operand) : null;
        if ($operation === null || $operation[0] !== 'var') {
            return null;
        }

        return $this->clockName($operation[1]);
    }

    private function clockName(mixed $reference): ?string
    {
        if (is_array($reference) && array_is_list($reference) && $reference !== []) {
            $reference = $reference[0];
        }

        return is_string($reference) && in_array($reference, self::CLOCK_VARIABLES, true) ? $reference : null;
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array{0: string, 1: mixed}|null a JSON Logic node `{op: args}`
     */
    private function operation(array $node): ?array
    {
        if (count($node) !== 1) {
            return null;
        }
        $op = array_key_first($node);

        return is_string($op) ? [$op, $node[$op]] : null;
    }

    /**
     * The moment (Unix timestamp) a comparison partner names, or null when it
     * names none the server can read.
     *
     * @param array<string, mixed> $variables
     */
    private function momentOf(mixed $peer, array $variables): ?int
    {
        try {
            $value = is_array($peer) ? JsonLogic::apply($peer, $variables) : $peer;
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $text = trim($value);
        // A time of day alone ("08:30") has no date: it is today's moment, read below.
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $text) === 1) {
            return strtotime($text) ?: null;
        }

        $moment = strtotime($text);

        return $moment === false ? null : $moment;
    }

    /**
     * The comparison as if the clock stood on the left: `5 < clock` is `clock > 5`.
     */
    private function clockOnLeft(string $op, int|string $clockIndex): string
    {
        if ((int) $clockIndex === 0) {
            return $op;
        }

        return match ($op) {
            '>' => '<',
            '>=' => '<=',
            '<' => '>',
            '<=' => '>=',
            default => $op,
        };
    }

    /**
     * The next moment after `$now` at which `clock <op> moment` can give a
     * different answer, or null when it cannot change any more.
     *
     * The clock shows whole days (`current_date`), minutes (`current_time`) or
     * seconds (`current_datetime`), so it passes the moment at the start of its
     * unit (`>=` and `<` flip there) or at the end of it (`>` and `<=` flip
     * there); an equality flips at both. With no known operator both count.
     *
     * @param string|null $op the comparison with the clock on the left, null when unknown
     */
    private function nextFlip(string $clock, int $moment, int $now, ?string $op = null): ?int
    {
        $atStart = $op === null || in_array($op, ['>=', '<', '==', '===', '!=', '!=='], true);
        $atEnd = $op === null || in_array($op, ['>', '<=', '==', '===', '!=', '!=='], true);

        $candidates = [];
        if ($clock === 'current_date') {
            if ($atStart) {
                $candidates[] = $this->startOfDay($moment);
            }
            if ($atEnd) {
                $candidates[] = $this->startOfDay($moment, 1);
            }
        } elseif ($clock === 'current_time') {
            $candidates = $this->timeOfDayCandidates($moment, $now, $atStart, $atEnd);
        } else {
            if ($atStart) {
                $candidates[] = $moment;
            }
            if ($atEnd) {
                $candidates[] = $moment + 1;
            }
        }

        $future = array_filter($candidates, static fn (int $candidate): bool => $candidate > $now);

        return $future === [] ? null : min($future);
    }

    /**
     * A time of day repeats every day: today's and tomorrow's occurrence (the
     * start) and the minute after each (the end; the clock shows minutes).
     *
     * @return list<int>
     */
    private function timeOfDayCandidates(int $moment, int $now, bool $atStart, bool $atEnd): array
    {
        $secondsIntoDay = $moment - $this->startOfDay($moment);
        $candidates = [];
        foreach ([0, 1] as $days) {
            $start = $this->startOfDay($now, $days);
            if ($atStart) {
                $candidates[] = $start + $secondsIntoDay;
            }
            if ($atEnd) {
                $candidates[] = $start + $secondsIntoDay + 60;
            }
        }

        return $candidates;
    }

    /** Midnight (default timezone) of the day of `$moment`, `$addDays` days later. */
    private function startOfDay(int $moment, int $addDays = 0): int
    {
        $day = (new \DateTimeImmutable('@' . $moment))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0);

        return ($addDays === 0 ? $day : $day->modify('+' . $addDays . ' day'))->getTimestamp();
    }

    private function nextMidnight(int $now): int
    {
        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->modify('tomorrow')
            ->getTimestamp();
    }
}
