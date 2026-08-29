<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{ToolRepository, FamilyRepository};

final class ToolRepositoryTest extends TestCase
{
    private ToolRepository $tools;
    private int $fam;

    protected function setUp(): void
    {
        make_test_db();
        $this->fam = (new FamilyRepository())->createPending('owner@skaz-kray.ru', 'H', 'Поместье Владельца');
        $this->tools = new ToolRepository();
    }

    public function test_create_defaults_available(): void
    {
        $id = $this->tools->create($this->fam, 'Перфоратор', 'Электроинструмент', 'Bosch', 'рабочий', null, '2026-08-29 10:00:00');
        $t = $this->tools->findById($id);
        $this->assertSame('Перфоратор', $t['name']);
        $this->assertSame('available', $t['status']);
        $this->assertSame($this->fam, (int) $t['family_id']);
    }

    public function test_update_and_set_status(): void
    {
        $id = $this->tools->create($this->fam, 'Лобзик', 'Электро', null, null, null, '2026-08-29 10:00:00');
        $this->tools->update($id, 'Электролобзик', 'Электроинструмент', 'мощный', 'ок', 'без залога', '2026-08-29 11:00:00');
        $this->tools->setStatus($id, 'maintenance');
        $t = $this->tools->findById($id);
        $this->assertSame('Электролобзик', $t['name']);
        $this->assertSame('maintenance', $t['status']);
        $this->assertSame('без залога', $t['terms']);
    }

    public function test_catalog_excludes_hidden_and_filters(): void
    {
        $a = $this->tools->create($this->fam, 'Дрель аккумуляторная', 'Электроинструмент', null, null, null, '2026-08-29 10:00:00');
        $b = $this->tools->create($this->fam, 'Грабли', 'Садовый', null, null, null, '2026-08-29 10:01:00');
        $h = $this->tools->create($this->fam, 'Секрет', 'Прочее', null, null, null, '2026-08-29 10:02:00');
        $this->tools->setStatus($h, 'hidden');

        $all = $this->tools->listCatalog();
        $this->assertCount(2, $all); // скрытый не в каталоге

        // Подстрочный поиск. Ищем 'аккум' (совпадает регистр в SQLite; на проде
        // MariaDB utf8mb4_unicode_ci даёт полную регистронезависимость).
        $byWord = $this->tools->listCatalog('аккум');
        $this->assertCount(1, $byWord);
        $this->assertSame($a, (int) $byWord[0]['id']);

        $byCat = $this->tools->listCatalog('', 'Садовый');
        $this->assertCount(1, $byCat);
        $this->assertSame($b, (int) $byCat[0]['id']);

        $this->assertArrayHasKey('owner_name', $all[0]);
    }

    public function test_status_filter(): void
    {
        $a = $this->tools->create($this->fam, 'A', '', null, null, null, '2026-08-29 10:00:00');
        $b = $this->tools->create($this->fam, 'B', '', null, null, null, '2026-08-29 10:00:00');
        $this->tools->setStatus($b, 'on_loan');
        $this->assertCount(1, $this->tools->listCatalog('', '', 'available'));
        $this->assertCount(1, $this->tools->listCatalog('', '', 'on_loan'));
    }

    public function test_categories_and_list_by_family(): void
    {
        $this->tools->create($this->fam, 'A', 'Садовый', null, null, null, '2026-08-29 10:00:00');
        $this->tools->create($this->fam, 'B', 'Электроинструмент', null, null, null, '2026-08-29 10:00:00');
        $cats = $this->tools->categories();
        $this->assertContains('Садовый', $cats);
        $this->assertContains('Электроинструмент', $cats);
        $this->assertCount(2, $this->tools->listByFamily($this->fam));
    }

    public function test_delete(): void
    {
        $id = $this->tools->create($this->fam, 'X', '', null, null, null, '2026-08-29 10:00:00');
        $this->tools->delete($id);
        $this->assertNull($this->tools->findById($id));
    }
}
