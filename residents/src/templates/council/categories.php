<?php use SkazResidents\{View, Csrf}; /** @var array $income */ /** @var array $expense */ ?>
<section class="sovet-hero" style="margin-bottom:1rem">
    <p class="sovet-eyebrow">Бухгалтерия · только админ</p>
    <h1>Статьи бюджета</h1>
    <p>Статьи прихода и расхода для учёта. Архивная статья не предлагается при вводе новых операций, но остаётся в исторических отчётах.</p>
    <p class="sovet-hero-actions"><a class="res-btn res-btn--ghost" href="/sovet/buhgalteriya">← К операциям</a></p>
</section>

<?php
$block = static function (string $title, array $rows, string $kind) {
    ?>
    <div class="res-card">
        <h2 style="font-size:1.1rem"><?= View::e($title) ?></h2>
        <table class="sovet-members">
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td>
                        <form method="post" action="/sovet/buhgalteriya/statyi/<?= (int) $c['id'] ?>/pereimenovat" class="sovet-sub-rename">
                            <?= Csrf::field() ?>
                            <input type="text" name="name" value="<?= View::e($c['name']) ?>" maxlength="160">
                            <button type="submit" class="res-btn" style="margin-top:0;padding:.35rem .9rem;font-size:.85rem">Сохранить</button>
                        </form>
                    </td>
                    <td style="text-align:right">
                        <?php if ((int) $c['is_active'] === 1): ?>
                            <span class="res-status res-status--published">активна</span>
                        <?php else: ?>
                            <span class="res-status res-status--rejected">архив</span>
                        <?php endif; ?>
                        <form method="post" action="/sovet/buhgalteriya/statyi/<?= (int) $c['id'] ?>/arhiv" style="display:inline;margin-left:.5rem">
                            <?= Csrf::field() ?>
                            <button type="submit" class="res-link-btn"><?= (int) $c['is_active'] === 1 ? 'В архив' : 'Вернуть' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="/sovet/buhgalteriya/statyi/dobavit" class="sovet-sub-add">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="<?= View::e($kind) ?>">
            <input type="text" name="name" placeholder="Новая статья" maxlength="160">
            <button type="submit" class="res-btn">Добавить</button>
        </form>
    </div>
    <?php
};
$block('Статьи прихода', $income, 'income');
$block('Статьи расхода', $expense, 'expense');
?>
