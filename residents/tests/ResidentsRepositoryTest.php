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
        // жителей скрыты (claim-гейт в ResidentsRepository::grouped).
        $pdo->exec("INSERT INTO families (id, email, telegram_id, password_hash, name, status) VALUES
            (1, 'agudariya@example.com', 111, 'x', 'Семья 1', 'active'),
            (2, 'solnyshko@example.com', 222, 'x', 'Семья 2', 'active')");
        // Два поместья: у первого — двое жителей, у второго — один.
        $pdo->exec("INSERT INTO households (id, glade, plot, estate_name, family_id, sort) VALUES
            (1, '(1) Обережная', '1', 'АгудариЯ', 1, 0),
            (2, '(4) Рассветная', '4', 'Солнышко', 2, 1)");
        $pdo->exec("INSERT INTO residents (household_id, full_name, skills, hometown, sort) VALUES
            (1, 'Руденко Сергей', 'компьютерный дизайн', 'Краснодар', 0),
            (1, 'Руденко Анна', 'драматургия', 'Ростов-на-Дону', 1),
            (2, 'Вишняков Андрей', 'пчеловодство', 'Москва', 0)");
        $this->repo = new ResidentsRepository();
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
