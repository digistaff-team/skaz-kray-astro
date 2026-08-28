<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\ImageRepository;

final class ImageRepositoryTest extends TestCase
{
    private ImageRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new ImageRepository();
    }

    public function test_add_and_list_for_owner(): void
    {
        $this->repo->add('entry', 5, 'a.jpg', 0);
        $this->repo->add('entry', 5, 'b.jpg', 1);
        $this->repo->add('product', 5, 'c.jpg', 0); // другой owner_type
        $imgs = $this->repo->listFor('entry', 5);
        $this->assertCount(2, $imgs);
        $this->assertSame('a.jpg', $imgs[0]['path']);
    }

    public function test_delete_for_owner(): void
    {
        $this->repo->add('entry', 5, 'a.jpg', 0);
        $this->repo->deleteFor('entry', 5);
        $this->assertCount(0, $this->repo->listFor('entry', 5));
    }
}
