<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Periode keuangan "bulanan" yang bisa dimulai di tanggal gajian.
 *
 * Kunci periode = bulan saat periode DIMULAI. Dengan awal tanggal 25,
 * periode "2026-10" berjalan 25 Okt – 24 Nov. Awal tanggal 1 = bulan kalender biasa.
 */
final class Period
{
    /** Batas 28 agar setiap bulan punya tanggal tersebut. */
    public const MAX_START_DAY = 28;

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly int $startDay,
    ) {}

    public static function normalizeDay(?int $day): int
    {
        return max(1, min(self::MAX_START_DAY, $day ?: 1));
    }

    /** Periode dari kunci "Y-m"; kunci kosong/tidak sah = periode berjalan. */
    public static function fromKey(?string $key, int $startDay = 1): self
    {
        $startDay = self::normalizeDay($startDay);

        if ($key && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key)) {
            return self::starting(CarbonImmutable::createFromFormat('!Y-m', $key), $startDay);
        }

        return self::containing(CarbonImmutable::today(), $startDay);
    }

    /** Periode yang memuat tanggal tertentu. */
    public static function containing(CarbonImmutable $date, int $startDay = 1): self
    {
        $startDay = self::normalizeDay($startDay);
        $month = $date->startOfMonth();

        return self::starting($date->day >= $startDay ? $month : $month->subMonth(), $startDay);
    }

    private static function starting(CarbonImmutable $month, int $startDay): self
    {
        $start = $month->startOfMonth()->setDay($startDay);

        return new self($start, $start->addMonthNoOverflow()->subDay(), $startDay);
    }

    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    public function previous(): self
    {
        return self::starting($this->start->startOfMonth()->subMonth(), $this->startDay);
    }

    /** @return array{0: string, 1: string} untuk whereBetween. */
    public function range(): array
    {
        return [$this->start->toDateString(), $this->end->toDateString()];
    }

    /** @return list<CarbonImmutable> */
    public function days(): array
    {
        $days = [];
        for ($d = $this->start; $d->lte($this->end); $d = $d->addDay()) {
            $days[] = $d;
        }

        return $days;
    }
}
