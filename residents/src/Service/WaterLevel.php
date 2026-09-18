<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Config;
use SkazResidents\Repository\WaterLevelRepository;

/**
 * Уровень воды в Шебше для панели на главной приложения (иконка-капля в хедере).
 *
 * Источник замеров — приложение shebsh-water-level на Vercel, которое скрапит
 * гидропост AllRivers: GET /api/water-level → {"water_level":см,"change_24h":см}.
 * Историю ведём свою (water_level_history): bin/water-level-snapshot.php раз в час.
 * Чужой /api/history не используем — он живёт в Vercel KV и уже отдавал 502.
 *
 * Вся арифметика — в системе высот Балтийской (БСВ), константы совпадают с
 * constants.ts того приложения. level_cm — сантиметры от нуля гидропоста, может
 * быть отрицательным. При правке любой отметки сверяйте панель целиком: крупную
 * цифру запаса, пороги статуса и линии на диаграмме.
 */
final class WaterLevel
{
    /** Ноль гидропоста, м БСВ. */
    public const GAUGE_ZERO_BSV = 38.158;
    /** Нижняя кромка моста — «НЯ затопления», м БСВ. */
    public const BRIDGE_BSV = 35.160;
    /** Опасная отметка — «ОЯ затопления», м БСВ. */
    public const HIGH_FLOOD_BSV = 36.160;

    /** Отметки в той же шкале, что level_cm (см от нуля гидропоста). */
    public const BRIDGE_CM = -300;      // (35.160 - 38.158) * 100
    public const HIGH_FLOOD_CM = -200;  // (36.160 - 38.158) * 100

    /** Пороги запаса до моста для цвета панели, см. Ориентир, правится по опыту. */
    private const CALM_GAP_CM  = 200;
    private const WATCH_GAP_CM = 50;

    /** Замер старше этого — при открытии панели спрашиваем источник заново, мин. */
    private const STALE_MINUTES = 90;

    /** Глубина диаграммы, дней. */
    private const CHART_DAYS = 30;

    public function __construct(
        private WaterLevelRepository $history = new WaterLevelRepository()
    ) {}

    /**
     * Данные панели: свежий замер (при надобности спросив источник), запас до
     * моста, подписи и геометрия диаграммы. 'level' === null — показать «нет данных».
     * @return array<string,mixed>
     */
    public function panel(string $now): array
    {
        $row = $this->history->latest();
        if ($this->isStale($row, $now)) {
            $fresh = $this->snapshot($now);
            if ($fresh !== null) { $row = $this->history->latest(); }
        }

        if (!$row) {
            return ['level' => null, 'chart' => null];
        }

        $level  = (float) $row['level_cm'];
        $change = (float) $row['change_24h'];
        $gap    = $this->bridgeGapCm($level);

        return [
            'level'       => $level,
            'levelBsv'    => $this->bsv($level),
            'levelLabel'  => $this->formatMeters($this->bsv($level), 2),
            'gap'         => $gap,
            'gapLabel'    => $this->formatDistance(abs($gap)),
            'flooded'     => $gap <= 0,
            'status'      => $this->status($gap),
            'change'      => $change,
            'changeLabel' => $this->changeLabel($change),
            'measuredAt'  => $this->mskTime((string) $row['measured_at']),
            'chart'       => $this->chart($this->history->dailyPoints(self::CHART_DAYS, $now)),
        ];
    }

    /**
     * Спросить источник и записать замер в историю. Возвращает записанные данные
     * или null, если источник недоступен (сеть, 502 у Vercel, мусор в ответе).
     * @return array{level_cm:float,change_24h:float}|null
     */
    public function snapshot(string $now): ?array
    {
        $data = $this->probe();
        if ($data === null) { return null; }
        $this->history->save($now, $data['level_cm'], $data['change_24h']);
        return $data;
    }

    /** Уровень в метрах БСВ. */
    public function bsv(float $levelCm): float
    {
        return $levelCm / 100 + self::GAUGE_ZERO_BSV;
    }

    /**
     * Запас до нижней кромки моста, см: больше нуля — вода ниже кромки,
     * ноль и меньше — вода на кромке или выше (мост под водой).
     */
    public function bridgeGapCm(float $levelCm): float
    {
        return self::BRIDGE_CM - $levelCm;
    }

    /** 'calm' | 'watch' | 'alert' по запасу до моста. */
    public function status(float $gapCm): string
    {
        if ($gapCm <= self::WATCH_GAP_CM) { return 'alert'; }
        if ($gapCm < self::CALM_GAP_CM)   { return 'watch'; }
        return 'calm';
    }

    /** «7,5 м» для дальних расстояний и «40 см» для близких. */
    public function formatDistance(float $cm): string
    {
        if ($cm < 100) { return round($cm) . ' см'; }
        return $this->formatMeters($cm / 100, 1) ;
    }

    /** Метры с русской запятой: 35.16 → «35,16 м». */
    public function formatMeters(float $m, int $decimals): string
    {
        return number_format($m, $decimals, ',', ' ') . ' м';
    }

    /** «поднялась на 12 см за сутки» / «опустилась на 8 см» / «без изменений». */
    public function changeLabel(float $changeCm): string
    {
        $abs = round(abs($changeCm));
        if ($abs < 1) { return 'за сутки без изменений'; }
        $verb = $changeCm > 0 ? 'поднялась' : 'опустилась';
        return 'за сутки ' . $verb . ' на ' . $abs . ' см';
    }

    /**
     * Геометрия диаграммы в координатах viewBox (0..$w, 0..$h): ломаная уровня,
     * заливка под ней и подписи краёв. null — точек слишком мало для линии.
     * Отметки моста и опасного уровня попадают в результат только когда входят
     * в диапазон данных: обычно вода много ниже моста, и линия ушла бы за рамку.
     *
     * @param array<int,array{date:string,level_cm:float,change_24h:float}> $points
     * @return array<string,mixed>|null
     */
    public function chart(array $points, int $w = 300, int $h = 110): ?array
    {
        if (count($points) < 2) { return null; }

        $levels = array_map(static fn(array $p): float => (float) $p['level_cm'], $points);
        $min = min($levels);
        $max = max($levels);
        if ($max - $min < 1) { $min -= 1; $max += 1; }   // штиль — ломаная по центру

        $pad = 8;
        $y = static function (float $cm) use ($min, $max, $h, $pad): float {
            $k = ($cm - $min) / ($max - $min);
            return round($h - $pad - $k * ($h - 2 * $pad), 1);
        };
        $x = static function (int $i) use ($points, $w): float {
            return round($i * $w / (count($points) - 1), 1);
        };

        $coords = [];
        foreach ($points as $i => $p) {
            $coords[] = $x($i) . ',' . $y((float) $p['level_cm']);
        }

        $marks = [];
        foreach ([['cm' => self::BRIDGE_CM, 'label' => 'кромка моста'],
                  ['cm' => self::HIGH_FLOOD_CM, 'label' => 'опасный уровень']] as $m) {
            if ($m['cm'] >= $min && $m['cm'] <= $max) {
                $marks[] = ['y' => $y((float) $m['cm']), 'label' => $m['label']];
            }
        }

        return [
            'w'         => $w,
            'h'         => $h,
            'poly'      => implode(' ', $coords),
            'area'      => '0,' . $h . ' ' . implode(' ', $coords) . ' ' . $w . ',' . $h,
            'minLabel'  => $this->formatMeters($this->bsv($min), 2),
            'maxLabel'  => $this->formatMeters($this->bsv($max), 2),
            'firstDate' => $this->shortDate($points[0]['date']),
            'lastDate'  => $this->shortDate($points[count($points) - 1]['date']),
            'days'      => count($points),
            'marks'     => $marks,
        ];
    }

    // --- Внутреннее ---

    /** @param array<string,mixed>|null $row */
    private function isStale(?array $row, string $now): bool
    {
        if (!$row) { return true; }
        $age = strtotime($now) - strtotime((string) $row['measured_at']);
        return $age > self::STALE_MINUTES * 60;
    }

    /**
     * Спросить текущий замер у источника, ничего не записывая. Таймаут короткий:
     * панель открывается по клику, и ждать чужой Vercel дольше пары секунд нельзя.
     * @return array{level_cm:float,change_24h:float}|null
     */
    public function probe(): ?array
    {
        $url = (string) (Config::get('water_level_api', 'https://shebsh-water-level.vercel.app/api/water-level')
            ?: 'https://shebsh-water-level.vercel.app/api/water-level');

        $raw = $this->httpGet($url);
        if ($raw === null) { return null; }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['water_level']) || !is_numeric($data['water_level'])) {
            error_log('WaterLevel: неожиданный ответ источника: ' . mb_substr($raw, 0, 200));
            return null;
        }
        return [
            'level_cm'   => (float) $data['water_level'],
            'change_24h' => is_numeric($data['change_24h'] ?? null) ? (float) $data['change_24h'] : 0.0,
        ];
    }

    private function httpGet(string $url, int $timeout = 4): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $res  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($res === false) { error_log('WaterLevel: curl ошибка: ' . $err); return null; }
            if ($code >= 400)   { error_log('WaterLevel: источник ответил ' . $code); return null; }
            return (string) $res;
        }

        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : (string) $res;
    }

    /**
     * 'Y-m-d H:i:s' (зона сервера, как во всех таблицах проекта) → 'HH:MM' по Москве:
     * жители читают время по МСК, а сервер живёт в UTC.
     */
    private function mskTime(string $stamp): string
    {
        try {
            $dt = new \DateTime($stamp);
            $dt->setTimezone(new \DateTimeZone('Europe/Moscow'));
            return $dt->format('H:i');
        } catch (\Exception) {
            return substr($stamp, 11, 5);
        }
    }

    /** 'YYYY-MM-DD' → '29 авг'. */
    private function shortDate(string $date): string
    {
        static $months = ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) { return $date; }
        return ((int) $m[3]) . ' ' . $months[((int) $m[2]) - 1];
    }
}
