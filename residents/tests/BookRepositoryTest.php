<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{BookRepository, FamilyRepository};

final class BookRepositoryTest extends TestCase
{
    private BookRepository $books;
    private int $fam;

    protected function setUp(): void
    {
        make_test_db();
        $this->fam = (new FamilyRepository())->createPending('owner@skaz-kray.ru', 'H', 'Поместье Владельца');
        $this->books = new BookRepository();
    }

    public function test_create_defaults_available(): void
    {
        $id = $this->books->create($this->fam, 'Понедельник начинается в субботу', 'Стругацкие', 'Фантастика', 'о НИИЧАВО', 'хорошее', '2026-08-29 10:00:00');
        $b = $this->books->findById($id);
        $this->assertSame('Понедельник начинается в субботу', $b['title']);
        $this->assertSame('Стругацкие', $b['author']);
        $this->assertSame('available', $b['status']);
        $this->assertSame($this->fam, (int) $b['family_id']);
    }

    public function test_update_and_set_status(): void
    {
        $id = $this->books->create($this->fam, 'Черновик', '', '', null, null, '2026-08-29 10:00:00');
        $this->books->update($id, 'Чистовик', 'Лукьяненко', 'Фантастика', 'аннотация', 'ок', '2026-08-29 11:00:00');
        $this->books->setStatus($id, 'maintenance');
        $b = $this->books->findById($id);
        $this->assertSame('Чистовик', $b['title']);
        $this->assertSame('Лукьяненко', $b['author']);
        $this->assertSame('maintenance', $b['status']);
    }

    public function test_catalog_excludes_hidden_and_filters(): void
    {
        $a = $this->books->create($this->fam, 'Дюна', 'Герберт', 'Фантастика', null, null, '2026-08-29 10:00:00');
        $b = $this->books->create($this->fam, 'Азбука садовода', 'Иванов', 'Садоводство', null, null, '2026-08-29 10:01:00');
        $h = $this->books->create($this->fam, 'Секрет', 'Автор', 'Прочее', null, null, '2026-08-29 10:02:00');
        $this->books->setStatus($h, 'hidden');

        $all = $this->books->listCatalog();
        $this->assertCount(2, $all);

        // Поиск по автору (совпадение регистра для SQLite; MariaDB — регистронезависим).
        $byAuthor = $this->books->listCatalog('Герберт');
        $this->assertCount(1, $byAuthor);
        $this->assertSame($a, (int) $byAuthor[0]['id']);

        $byGenre = $this->books->listCatalog('', 'Садоводство');
        $this->assertCount(1, $byGenre);
        $this->assertSame($b, (int) $byGenre[0]['id']);

        $this->assertArrayHasKey('owner_name', $all[0]);
    }

    public function test_status_filter(): void
    {
        $a = $this->books->create($this->fam, 'A', '', '', null, null, '2026-08-29 10:00:00');
        $b = $this->books->create($this->fam, 'B', '', '', null, null, '2026-08-29 10:00:00');
        $this->books->setStatus($b, 'on_loan');
        $this->assertCount(1, $this->books->listCatalog('', '', 'available'));
        $this->assertCount(1, $this->books->listCatalog('', '', 'on_loan'));
    }

    public function test_genres_and_list_by_family(): void
    {
        $this->books->create($this->fam, 'A', '', 'Фантастика', null, null, '2026-08-29 10:00:00');
        $this->books->create($this->fam, 'B', '', 'Детская', null, null, '2026-08-29 10:00:00');
        $genres = $this->books->genres();
        $this->assertContains('Фантастика', $genres);
        $this->assertContains('Детская', $genres);
        $this->assertCount(2, $this->books->listByFamily($this->fam));
    }

    public function test_delete(): void
    {
        $id = $this->books->create($this->fam, 'X', '', '', null, null, '2026-08-29 10:00:00');
        $this->books->delete($id);
        $this->assertNull($this->books->findById($id));
    }
}
