<?php
declare(strict_types=1);

/**
 * Calendário de atendimento: dias úteis de segunda a sexta, exceto feriados
 * nacionais e do Paraná, das 9h às 18h (Brasília). Usado nas metas internas.
 * Prazos legais NÃO são calculados aqui: eles dependem de regra própria e
 * conferência do advogado (workflow, seção 4 e etapa 2).
 */

function easter(int $year): DateTimeImmutable
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/** @return array<string,string> data => nome */
function holidays(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    $h = [
        "$year-01-01" => 'Confraternização Universal',
        "$year-04-21" => 'Tiradentes',
        "$year-05-01" => 'Dia do Trabalho',
        "$year-09-07" => 'Independência',
        "$year-10-12" => 'Nossa Senhora Aparecida',
        "$year-11-02" => 'Finados',
        "$year-11-15" => 'Proclamação da República',
        "$year-11-20" => 'Dia Nacional de Zumbi e da Consciência Negra',
        "$year-12-19" => 'Emancipação Política do Paraná',
        "$year-12-25" => 'Natal',
    ];
    $e = easter($year);
    $h[$e->modify('-2 days')->format('Y-m-d')] = 'Sexta-feira Santa';
    if (config('closed_on_optional_holidays', true)) {
        $h[$e->modify('-48 days')->format('Y-m-d')] = 'Carnaval';
        $h[$e->modify('-47 days')->format('Y-m-d')] = 'Carnaval';
        $h[$e->modify('+60 days')->format('Y-m-d')] = 'Corpus Christi';
    }
    ksort($h);
    return $cache[$year] = $h;
}

function is_business_day(DateTimeImmutable $d): bool
{
    $w = (int) $d->format('N');
    if ($w >= 6) {
        return false;
    }
    return !isset(holidays((int) $d->format('Y'))[$d->format('Y-m-d')]);
}

function open_hour(): int
{
    return (int) config('hours.start', 9);
}

function close_hour(): int
{
    return (int) config('hours.end', 18);
}

function is_open(?DateTimeImmutable $at = null): bool
{
    $at ??= now();
    if (!is_business_day($at)) {
        return false;
    }
    $h = (int) $at->format('G') + ((int) $at->format('i')) / 60;
    return $h >= open_hour() && $h < close_hour();
}

/** Próximo instante com atendimento humano (o próprio instante, se aberto). */
function next_opening(?DateTimeImmutable $at = null): DateTimeImmutable
{
    $at ??= now();
    if (is_open($at)) {
        return $at;
    }
    $d = $at;
    if (is_business_day($d) && (int) $d->format('G') < open_hour()) {
        return $d->setTime(open_hour(), 0);
    }
    do {
        $d = $d->modify('+1 day')->setTime(open_hour(), 0);
    } while (!is_business_day($d));
    return $d;
}

/** Soma horas úteis a partir de um instante. */
function add_business_hours(DateTimeImmutable $start, float $hours): DateTimeImmutable
{
    $cur = next_opening($start);
    $remaining = (int) round($hours * 60);
    while ($remaining > 0) {
        $endOfDay = $cur->setTime(close_hour(), 0);
        $available = intdiv($endOfDay->getTimestamp() - $cur->getTimestamp(), 60);
        if ($remaining <= $available) {
            return $cur->modify("+$remaining minutes");
        }
        $remaining -= $available;
        $cur = next_opening($endOfDay->modify('+1 minute'));
    }
    return $cur;
}

/** Soma dias úteis; o resultado fica no fim do expediente do dia alcançado. */
function add_business_days(DateTimeImmutable $start, int $days): DateTimeImmutable
{
    $d = $start;
    $added = 0;
    while ($added < $days) {
        $d = $d->modify('+1 day');
        if (is_business_day($d)) {
            $added++;
        }
    }
    return $d->setTime(close_hour(), 0);
}

/** Horas úteis decorridas entre dois instantes. */
function business_hours_between(DateTimeImmutable $a, DateTimeImmutable $b): float
{
    if ($b <= $a) {
        return 0.0;
    }
    $minutes = 0;
    $cur = $a;
    $guard = 0;
    while ($cur < $b && $guard++ < 4000) {
        if (!is_business_day($cur)) {
            $cur = $cur->modify('+1 day')->setTime(open_hour(), 0);
            continue;
        }
        $open = $cur->setTime(open_hour(), 0);
        $close = $cur->setTime(close_hour(), 0);
        $from = max($cur, $open);
        $to = min($b, $close);
        if ($to > $from) {
            $minutes += intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
        }
        $cur = $cur->modify('+1 day')->setTime(open_hour(), 0);
    }
    return $minutes / 60;
}

/** Texto amigável sobre quando haverá resposta humana. */
function human_reply_promise(?DateTimeImmutable $at = null): string
{
    $at ??= now();
    if (is_open($at)) {
        return 'Estamos em horário de atendimento. Nossa meta é que uma pessoa da equipe fale com você em até 1 hora útil.';
    }
    $n = next_opening($at);
    return 'Agora estamos fora do horário de atendimento (dias úteis, das ' . open_hour() . 'h às ' . close_hour() . 'h). '
        . 'A equipe retoma ' . fmt_when($n) . ' e sua mensagem será uma das primeiras lidas.';
}
