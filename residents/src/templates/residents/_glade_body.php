<?php
/**
 * Поместья одной поляны — список карточек «поместье → жители, авто, питомцы».
 *
 * Отдельный файл, потому что рисуется из двух мест: со всей страницей
 * справочника и в одиночку, когда поляну подгружают по клику (ленивый режим
 * MAX, ResidentsController::glade). Разметка обязана совпадать до мелочи —
 * поэтому она здесь одна.
 *
 * Ожидает: $hhs (поместья поляны), $gnum (номер поляны для подписи участка),
 * $open (раскрывать ли поместья сразу — так показываются результаты поиска).
 *
 * @var array $hhs @var int $gnum @var bool $open
 */
use SkazResidents\View;

// Ссылка на VK: значение в таблице — «vk.com/xxx» или «id123»; приводим к полному URL.
$vkUrl = static function (string $v): string {
    $v = trim($v);
    if ($v === '') { return ''; }
    if (preg_match('~^https?://~i', $v)) { return $v; }
    return 'https://' . ltrim($v, '/');
};
// Слова имени в нижнем регистре (для сличения ФИО жителя с именем Telegram-профиля).
$nameTokens = static function (string $s): array {
    $out = [];
    foreach (preg_split('~[\s]+~u', mb_strtolower(trim($s))) as $t) { if ($t !== '') { $out[] = $t; } }
    return $out;
};
// Оценка совпадения ФИО жителя ($res) с именем Telegram-профиля ($tg). Эвристика:
// точное слово = 2, совпадение по началу слова (≥3 симв.: Бобков/Бобкова, Александр/
// Саш — нет, Алекс/Александр — да) = 1. Чем выше, тем вероятнее это тот самый человек.
$nameScore = static function (array $res, array $tg): int {
    $s = 0;
    foreach ($tg as $t) {
        $best = 0;
        foreach ($res as $r) {
            if ($r === $t) { $best = 2; break; }
            if (mb_strlen($r) >= 3 && mb_strlen($t) >= 3
                && (mb_strpos($r, $t) === 0 || mb_strpos($t, $r) === 0)) { $best = 1; }
        }
        $s += $best;
    }
    return $s;
};
?>
    <?php foreach ($hhs as $h): ?>
        <?php if (empty($h['people'])): /* свободный участок — статичная плашка, без раскрытия */ ?>
        <section class="res-hh res-hh--free">
            <div class="res-hh-head res-hh-head--static">
                <span class="res-hh-title">
                    <b class="res-hh-name"><?= View::e(($h['head_name'] || $h['estate_name'] !== '') ? \SkazResidents\HouseholdName::title((string) $h['estate_name'], $h['head_name']) : 'Свободный участок') ?></b>
                    <span class="res-hh-meta"><?php if ($h['plot'] !== ''): ?>участок <?= $gnum ?>-<?= View::e($h['plot']) ?><?php endif; ?></span>
                </span>
            </div>
        </section>
        <?php continue; endif; ?>
        <section class="res-hh<?= $open ? ' res-hh--open' : '' ?>">
            <button type="button" class="res-hh-head" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                <span class="res-hh-title">
                    <b class="res-hh-name"><?= View::e(\SkazResidents\HouseholdName::title((string) $h['estate_name'], $h['head_name'])) ?></b>
                    <span class="res-hh-meta">
                        <?php $mp = []; if ($h['plot'] !== '') { $mp[] = 'участок ' . $gnum . '-' . $h['plot']; } ?>
                        <?= View::e(implode(' · ', $mp)) ?>
                    </span>
                </span>
                <span class="res-hh-count"><?= count($h['people']) ?> <?= View::e(plural_ru(count($h['people']), 'житель', 'жителя', 'жителей')) ?></span>
                <span class="res-hh-chevron" aria-hidden="true"></span>
            </button>
            <ul class="res-people">
                <?php
                // «Tg» рядом с жителем: сопоставляем каждый подключённый аккаунт семьи
                // (по его Telegram-имени) с самым похожим по ФИО жителем. Эвристика
                // допускает неполное совпадение (Бобков/Бобкова, Алекс/Александр).
                // Один житель — один аккаунт (жадно), чтобы супруги с общей фамилией
                // не «слиплись» на одном человеке.
                $tgByPerson = []; $tgUsed = [];
                foreach (($h['accounts'] ?? []) as $acc) {
                    $accUser = trim((string) ($acc['tg_username'] ?? ''));
                    $accTokens = $nameTokens((string) ($acc['tg_name'] ?? ''));
                    if ($accUser === '' || !$accTokens) { continue; }
                    $bestId = 0; $bestScore = 0;
                    foreach ($h['people'] as $pp) {
                        $pid = (int) $pp['id'];
                        if (isset($tgUsed[$pid])) { continue; }
                        $sc = $nameScore($nameTokens((string) $pp['full_name']), $accTokens);
                        if ($sc > $bestScore) { $bestScore = $sc; $bestId = $pid; }
                    }
                    if ($bestId > 0 && $bestScore > 0) { $tgByPerson[$bestId] = $accUser; $tgUsed[$bestId] = true; }
                }
                ?>
                <?php foreach ($h['people'] as $p): ?>
                    <li class="res-person">
                        <div class="res-person-main">
                            <b><?= View::e($p['full_name']) ?></b>
                            <?php if ($p['birth_raw'] !== ''): ?><span class="res-meta">р. <?= View::e($p['birth_raw']) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($p['skills'])): ?>
                            <div class="res-person-skills"><?= View::e($p['skills']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($p['community_role'])): ?>
                            <div class="res-meta">Для поселения: <?= View::e($p['community_role']) ?></div>
                        <?php endif; ?>
                        <?php
                        $where = $p['residence'] !== '' ? $p['residence'] : '';
                        if ($where === '' && $p['moved_text'] !== '') { $where = 'переехали ' . $p['moved_text']; }
                        ?>
                        <?php if ($p['hometown'] !== '' || $where !== ''): ?>
                            <div class="res-meta">
                                <?php if ($p['hometown'] !== ''): ?>родом из <?= View::e($p['hometown']) ?><?php endif; ?>
                                <?php if ($where !== ''): ?><?= $p['hometown'] !== '' ? ' · ' : '' ?><?= View::e($where) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="res-person-contacts">
                            <?php if ($p['phone'] !== ''): ?>
                                <a href="tel:<?= View::e(preg_replace('~[^\d+]~', '', $p['phone'])) ?>"><?= View::e($p['phone']) ?></a>
                            <?php endif; ?>
                            <?php if ($p['email'] !== ''): ?>
                                <a href="mailto:<?= View::e($p['email']) ?>"><?= View::e($p['email']) ?></a>
                            <?php endif; ?>
                            <?php if ($p['vk'] !== ''): ?>
                                <a href="<?= View::e($vkUrl($p['vk'])) ?>" target="_blank" rel="noopener">VK</a>
                            <?php endif; ?>
                            <?php if (isset($tgByPerson[(int) $p['id']])): ?>
                                <a href="https://t.me/<?= View::e($tgByPerson[(int) $p['id']]) ?>" target="_blank" rel="noopener" class="js-tg-link">Tg</a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($p['images'])): ?>
                            <div class="photo-preview">
                                <?php foreach ($p['images'] as $img): ?>
                                    <?php $u = entry_image_url($img['path']); ?>
                                    <img class="photo-thumb js-photo-full" src="<?= View::e(entry_image_thumb($img['path'], 240)) ?>" data-full="<?= View::e($u) ?>" alt="<?= View::e($p['full_name']) ?>" loading="lazy">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!empty($h['cars'])): ?>
                    <li class="res-person res-cars">
                        <div class="res-meta"><b>Автомобили поместья:</b></div>
                        <?php foreach ($h['cars'] as $car): ?>
                            <div class="res-asset">
                                <span class="res-asset-name"><?= View::e($car['title']) ?><?php if ($car['plate'] !== ''): ?> (<?= View::e($car['plate']) ?>)<?php endif; ?></span>
                                <?php if (!empty($car['note'])): ?><span class="res-meta"><?= View::e($car['note']) ?></span><?php endif; ?>
                                <?php if (!empty($car['images'])): ?>
                                    <div class="photo-preview">
                                        <?php foreach ($car['images'] as $img): ?>
                                            <?php $u = entry_image_url($img['path']); ?>
                                            <img class="photo-thumb js-photo-full" src="<?= View::e(entry_image_thumb($img['path'], 240)) ?>" data-full="<?= View::e($u) ?>" alt="<?= View::e($car['title']) ?>" loading="lazy">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </li>
                <?php endif; ?>
                <?php if (!empty($h['pets'])): ?>
                    <li class="res-person res-pets">
                        <div class="res-meta"><b>Питомцы поместья:</b></div>
                        <?php foreach ($h['pets'] as $pet): ?>
                            <div class="res-asset">
                                <span class="res-asset-name"><?= View::e($pet['name']) ?><?php if ($pet['kind'] !== ''): ?> (<?= View::e($pet['kind']) ?>)<?php endif; ?></span>
                                <?php if (!empty($pet['note'])): ?><span class="res-meta"><?= View::e($pet['note']) ?></span><?php endif; ?>
                                <?php if (!empty($pet['images'])): ?>
                                    <div class="photo-preview">
                                        <?php foreach ($pet['images'] as $img): ?>
                                            <?php $u = entry_image_url($img['path']); ?>
                                            <img class="photo-thumb js-photo-full" src="<?= View::e(entry_image_thumb($img['path'], 240)) ?>" data-full="<?= View::e($u) ?>" alt="<?= View::e($pet['name']) ?>" loading="lazy">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </li>
                <?php endif; ?>
            </ul>
        </section>
    <?php endforeach; ?>
