<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Database;
use SkazResidents\Repository\ResidentsRepository;

final class ResidentsRepositoryTest extends TestCase
{
    private ResidentsRepository $repo;

    protected function setUp(): void
    {
        $pdo = make_test_db();
        // Поместья привязаны семьёй через Telegram (active + telegram_id) — иначе ПДн
        // жителей скрыты (claim-гейт в ResidentsRepository::grouped). @username нужен
        // для показа ссылки «Tg». Семья 3 — совладелец поместья 1 (второй участник).
        $pdo->exec("INSERT INTO families (id, email, telegram_id, telegram_username, password_hash, name, status) VALUES
            (1, 'agudariya@example.com', 111, 'sergey_tg', 'x', 'Сергей Руденко', 'active'),
            (2, 'solnyshko@example.com', 222, 'andrey_tg', 'x', 'Андрей Вишняков', 'active'),
            (3, 'anna@example.com', 333, 'anna_tg', 'x', 'Анна Руденко', 'active')");
        // Два поместья: у первого — двое жителей, у второго — один.
        $pdo->exec("INSERT INTO households (id, glade, plot, estate_name, family_id, sort) VALUES
            (1, '(1) Обережная', '1', 'АгудариЯ', 1, 0),
            (2, '(4) Рассветная', '4', 'Солнышко', 2, 1)");
        // Владельцы: семьи 1 и 3 — поместье 1 (основной + совладелец), семья 2 — поместье 2.
        $pdo->exec("INSERT INTO household_owners (household_id, family_id) VALUES
            (1, 1), (1, 3), (2, 2)");
        $pdo->exec("INSERT INTO residents (household_id, full_name, skills, hometown, sort) VALUES
            (1, 'Руденко Сергей', 'компьютерный дизайн', 'Краснодар', 0),
            (1, 'Руденко Анна', 'драматургия', 'Ростов-на-Дону', 1),
            (2, 'Вишняков Андрей', 'пчеловодство', 'Москва', 0)");
        // Питомец с двумя фото и авто с одним фото — для показа миниатюр в справочнике.
        $pdo->exec("INSERT INTO household_pets (id, household_id, name, kind, sort) VALUES
            (1, 1, 'Барсик', 'кот', 0)");
        $pdo->exec("INSERT INTO household_cars (id, household_id, title, plate, sort) VALUES
            (1, 1, 'Нива', 'А123ВС', 0)");
        $pdo->exec("INSERT INTO images (owner_type, owner_id, path, sort) VALUES
            ('pet', 1, 'pets/barsik-1.jpg', 0),
            ('pet', 1, 'pets/barsik-2.jpg', 1),
            ('car', 1, 'cars/niva-1.jpg', 0)");
        $this->repo = new ResidentsRepository();
    }

    public function test_grouped_attaches_photos_to_pets(): void
    {
        $groups = $this->repo->grouped();
        $this->assertCount(1, $groups[0]['pets']);
        $pet = $groups[0]['pets'][0];
        $this->assertSame('Барсик', $pet['name']);
        $this->assertCount(2, $pet['images']);
        $this->assertSame('pets/barsik-1.jpg', $pet['images'][0]['path']);
        // У поместья без питомцев — пустой список.
        $this->assertSame([], $groups[1]['pets']);
    }

    public function test_grouped_lists_all_connected_accounts(): void
    {
        $groups = $this->repo->grouped();
        // Поместье 1 — два подключённых аккаунта (основной + совладелец), оба с @username.
        $users = array_map(static fn($a) => $a['tg_username'], $groups[0]['accounts']);
        sort($users);
        $this->assertSame(['anna_tg', 'sergey_tg'], $users);
        // Поместье 2 — один аккаунт.
        $this->assertCount(1, $groups[1]['accounts']);
        $this->assertSame('andrey_tg', $groups[1]['accounts'][0]['tg_username']);
    }

    public function test_grouped_attaches_photos_to_cars(): void
    {
        $groups = $this->repo->grouped();
        $this->assertCount(1, $groups[0]['cars']);
        $car = $groups[0]['cars'][0];
        $this->assertSame('Нива', $car['title']);
        $this->assertCount(1, $car['images']);
        $this->assertSame('cars/niva-1.jpg', $car['images'][0]['path']);
        // У поместья без авто — пустой список.
        $this->assertSame([], $groups[1]['cars']);
    }

    public function test_grouped_nests_people_under_households(): void
    {
        $groups = $this->repo->grouped();
        $this->assertCount(2, $groups);
        $this->assertSame('АгудариЯ', $groups[0]['estate_name']);
        $this->assertCount(2, $groups[0]['people']);
        $this->assertSame('Руденко Сергей', $groups[0]['people'][0]['full_name']);
        $this->assertCount(1, $groups[1]['people']);
    }

    public function test_search_by_person_skill_keeps_whole_household(): void
    {
        $groups = $this->repo->grouped('пчеловод');
        $this->assertCount(1, $groups);
        $this->assertSame('Солнышко', $groups[0]['estate_name']);
    }

    public function test_search_by_name_is_case_insensitive(): void
    {
        $groups = $this->repo->grouped('руденко');
        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]['people']);
    }

    public function test_search_no_match_returns_empty(): void
    {
        $this->assertSame([], $this->repo->grouped('кузнечное дело'));
    }

    public function test_stats_counts_rows(): void
    {
        $stats = $this->repo->stats();
        $this->assertSame(2, $stats['households']);
        $this->assertSame(3, $stats['people']);
    }
}
