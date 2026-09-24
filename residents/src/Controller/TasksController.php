<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, View};
use SkazResidents\Service\MyTasks;

/**
 * «Мои дела» — всё, что ждёт действия жителя: заявки на его инструменты и
 * книги, брони его поездок, отказы модерации, просроченный возврат, закупки.
 * Собирается из текущих статусов, см. {@see MyTasks}.
 */
final class TasksController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('tasks/index', ['tasks' => MyTasks::forCurrent()], 'Мои дела');
    }
}
