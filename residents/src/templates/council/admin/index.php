<?php use SkazResidents\{Csrf, View}; ?>
<h1>Участники совета</h1>
<p class="res-meta">Аккаунты заводятся по приглашению. При добавлении генерируется пароль — он показывается здесь и отправляется на email. Член совета может сменить его сам.</p>

<div class="res-card">
    <h2>Добавить члена совета</h2>
    <form class="res-form" method="post" action="/sovet/upravlenie/dobavit">
        <?= Csrf::field() ?>
        <label>Email
            <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required>
            <?php if (!empty($errors['email'])): ?><span class="sovet-err"><?= View::e($errors['email']) ?></span><?php endif; ?>
        </label>
        <label>Имя
            <input type="text" name="name" maxlength="160" value="<?= View::e($old['name'] ?? '') ?>" required>
            <?php if (!empty($errors['name'])): ?><span class="sovet-err"><?= View::e($errors['name']) ?></span><?php endif; ?>
        </label>
        <label>Роль
            <select name="role">
                <option value="member"<?= (($old['role'] ?? '') !== 'admin') ? ' selected' : '' ?>>член совета</option>
                <option value="admin"<?= (($old['role'] ?? '') === 'admin') ? ' selected' : '' ?>>администратор</option>
            </select>
        </label>
        <button class="res-btn" type="submit">Добавить</button>
    </form>
</div>

<div class="res-card">
    <h2>Список аккаунтов</h2>
    <p class="res-meta">«Дежурный председатель» — переходящая роль, назначается по внешнему графику дежурств. Текущий дежурный (и любой администратор) может редактировать карточку ближайшего собрания.</p>
    <table class="sovet-members">
        <thead><tr><th>Имя</th><th>Telegram / Email</th><th>Роль</th><th>Статус</th><th>Дежурный</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($members as $m): $mid = (int) $m['id']; $isDuty = !empty($m['is_duty_chair']); $bound = !empty($m['telegram_id']); ?>
            <tr>
                <td><?= View::e($m['name']) ?></td>
                <td><?= $bound ? 'Telegram привязан' : ((strpos((string) $m['email'], '@telegram.local') !== false) ? '<span class="res-meta">ждёт входа</span>' : View::e($m['email'])) ?></td>
                <td><?= View::e(\SkazResidents\CouncilData::roleLabel($m)) ?></td>
                <td><?= $m['status'] === 'active' ? 'активен' : 'заблокирован' ?></td>
                <td><?= $isDuty ? '<strong>✓ дежурит</strong>' : '' ?></td>
                <td class="sovet-member-actions">
                    <form method="post" action="/sovet/upravlenie/status">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $mid ?>">
                        <button class="res-link-btn<?= $m['status'] === 'active' ? ' sovet-danger' : '' ?>" type="submit"><?= $m['status'] === 'active' ? 'заблокировать' : 'разблокировать' ?></button>
                    </form>
                    <?php if ($bound): ?>
                        <form method="post" action="/sovet/upravlenie/otvyazat-telegram">
                            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $mid ?>">
                            <button class="res-link-btn sovet-danger" type="submit">отвязать Telegram</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isDuty): ?>
                        <form method="post" action="/sovet/upravlenie/dezhurnyy">
                            <?= Csrf::field() ?><input type="hidden" name="id" value="0">
                            <button class="res-link-btn" type="submit">снять дежурство</button>
                        </form>
                    <?php elseif ($m['status'] === 'active'): ?>
                        <form method="post" action="/sovet/upravlenie/dezhurnyy">
                            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $mid ?>">
                            <button class="res-link-btn" type="submit">сделать дежурным</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
