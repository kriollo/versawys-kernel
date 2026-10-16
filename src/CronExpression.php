<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Expresión cron estándar de 5 campos: minuto hora día-del-mes mes día-de-la-semana.
 * Acepta `*`, números, rangos (`1-5`), listas (`1,15`) y pasos (`*\/5`, `0-30/10`). Domingo es 0 o 7.
 * Como en cron, si se restringen día del mes y día de la semana, basta que coincida uno.
 */
final class CronExpression
{
    /** @var array<int, array{int, int}> mínimo y máximo de cada campo */
    private const array RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /** @var list<list<int>> */
    private readonly array $fields;

    /** Día del mes y día de la semana restringidos (no `*`): basta que coincida uno */
    private readonly bool $eitherDay;

    public function __construct(
        public readonly string $expression,
    ) {
        $parts = preg_split('/\s+/', trim($expression));
        if ($parts === false || count($parts) !== 5) {
            throw new InvalidArgumentException("Expresión cron inválida (se esperan 5 campos): {$expression}");
        }

        $fields = [];
        foreach ($parts as $i => $part) {
            $fields[] = self::parse($part, ...self::RANGES[$i]);
        }

        // 7 y 0 son domingo
        if (in_array(7, $fields[4], true)) {
            $fields[4][] = 0;
        }

        $this->fields = $fields;
        $this->eitherDay = $parts[2] !== '*' && $parts[4] !== '*';
    }

    public function isDue(DateTimeInterface $at): bool
    {
        [$minutes, $hours, $days, $months, $weekdays] = $this->fields;

        if (
            !in_array((int) $at->format('i'), $minutes, true)
            || !in_array((int) $at->format('G'), $hours, true)
            || !in_array((int) $at->format('n'), $months, true)
        ) {
            return false;
        }

        $dayMatches = in_array((int) $at->format('j'), $days, true);
        $weekdayMatches = in_array((int) $at->format('w'), $weekdays, true);

        return $this->eitherDay ? $dayMatches || $weekdayMatches : $dayMatches && $weekdayMatches;
    }

    /** La próxima vez que corresponde, desde el minuto siguiente a `$from`. Busca hasta un año. */
    public function nextRun(DateTimeInterface $from): ?DateTimeImmutable
    {
        $at = DateTimeImmutable::createFromInterface($from)->setTime(
            (int) $from->format('G'),
            (int) $from->format('i'),
        );
        $minute = new DateInterval('PT1M');
        for ($i = 0; $i < 527_040; $i++) {
            $at = $at->add($minute);
            if ($this->isDue($at)) {
                return $at;
            }
        }

        return null;
    }

    /** @return list<int> */
    private static function parse(string $field, int $min, int $max): array
    {
        $values = [];
        foreach (explode(',', $field) as $item) {
            if (preg_match('#^(\*|\d+(?:-\d+)?)(?:/(\d+))?$#', $item, $m) !== 1) {
                throw new InvalidArgumentException("Campo cron inválido: {$field}");
            }

            if ($m[1] === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($m[1], '-')) {
                [$from, $to] = array_map(intval(...), explode('-', $m[1]));
            } else {
                // `5/15` es «desde el 5, cada 15»; `5` solo, ese valor
                $from = (int) $m[1];
                $to = isset($m[2]) ? $max : $from;
            }

            $step = isset($m[2]) ? (int) $m[2] : 1;
            if ($from < $min || $to > $max || $from > $to || $step < 1) {
                throw new InvalidArgumentException("Campo cron fuera de rango: {$field}");
            }

            for ($v = $from; $v <= $to; $v += $step) {
                $values[] = $v;
            }
        }

        return array_values(array_unique($values));
    }
}
