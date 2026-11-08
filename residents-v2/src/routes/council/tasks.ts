import { Router } from 'express';
import { AuthRequest, requireCouncil } from '../../middleware/auth';
import { db } from '../../lib/db';
import {
  httpError, councilName, pickPriority, pickStatus, pickSort, pickDate,
  pickCategory, pickTiming, parseAmount, EXPENSE_TIMINGS,
} from '../../lib/councilHelpers';
import { syncExpense, requestExpenseIfNeeded } from '../../lib/councilExpense';

const router = Router();

/**
 * Доска задач Совета — порт residents/src/Controller/Council/TaskController.php.
 *
 * Совместная модель: любой вошедший член совета может добавить задачу, взять её,
 * отметить выполненной, править и удалять (как в эталоне-портале).
 *
 * Фото задач (легаси TaskController::handleUploads) не портированы: в v2 загрузка
 * файлов отсутствует во всех разделах (дневник, ярмарка, закупки) — это общий
 * непорченный кусок, и решать его надо одним механизмом хранения, а не здесь.
 * Удаление уже загруженных фото работает: DELETE /tasks/:id/photos/:imgId.
 */

const PHOTO_OWNER = 'task';
const MAX_PHOTOS = 10;

/** Задача с подзадачами и счётчиками — как listWithSubtasks в легаси. */
type TaskWithSubs = Awaited<ReturnType<typeof db.councilTask.findFirst>> & {
  subtasks: Array<{ id: number; title: string; done: boolean; position: number }>;
  total_count: number;
  done_count: number;
};

const PRIORITY_RANK: Record<string, number> = { высокая: 0, средняя: 1, низкая: 2 };

/**
 * Сортировка как в CouncilTaskRepository::sort/sortCompleted — делается в JS,
 * а не в SQL: порядок должен совпадать у MariaDB-легаси и Postgres.
 */
function sortActive<T extends { priority: string; progress: number; spent: unknown; createdAt: Date; id: number }>(
  tasks: T[], sort: string,
): T[] {
  const byId = (a: T, b: T) => a.id - b.id;
  return [...tasks].sort((a, b) => {
    switch (sort) {
      case 'progress':
        return b.progress - a.progress;
      case 'spent':
        return Number(b.spent) - Number(a.spent);
      case 'created': // старые наверху; при равенстве секунд — старше по id
        return a.createdAt.getTime() - b.createdAt.getTime() || byId(a, b);
      default: // priority
        return (PRIORITY_RANK[a.priority] ?? 1) - (PRIORITY_RANK[b.priority] ?? 1);
    }
  });
}

/** Архив: свежие сверху, дата выполнения (или создания у старых строк), при равенстве — новее по id. */
function sortArchive<T extends { completedAt: Date | null; createdAt: Date; id: number }>(tasks: T[]): T[] {
  const key = (t: T) => (t.completedAt ?? t.createdAt).getTime();
  return [...tasks].sort((a, b) => key(b) - key(a) || b.id - a.id);
}

/** Обогащение задач подзадачами и счётчиками (без N+1: один запрос на список). */
async function withSubtasks(taskIds: number[]) {
  if (taskIds.length === 0) return new Map<number, TaskWithSubs['subtasks']>();
  const subs = await db.councilSubtask.findMany({
    where: { taskId: { in: taskIds } },
    orderBy: [{ position: 'asc' }, { id: 'asc' }],
    select: { id: true, taskId: true, title: true, done: true, position: true },
  });
  const map = new Map<number, TaskWithSubs['subtasks']>();
  for (const s of subs) {
    const list = map.get(s.taskId) ?? [];
    list.push({ id: s.id, title: s.title, done: s.done, position: s.position });
    map.set(s.taskId, list);
  }
  return map;
}

/**
 * GET /api/council/tasks?sort=created|priority|progress|spent
 * Активные и архивные задачи + фото одним запросом (порт TaskController::index).
 */
router.get('/tasks', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const sort = pickSort(req.query.sort);
    const [active, archive] = await Promise.all([
      db.councilTask.findMany({ where: { status: { not: 'выполнена' } } }),
      db.councilTask.findMany({ where: { status: 'выполнена' } }),
    ]);

    const allIds = [...active, ...archive].map((t) => t.id);
    const [subsMap, photos] = await Promise.all([
      withSubtasks(allIds),
      allIds.length
        ? db.image.findMany({
            where: { ownerType: PHOTO_OWNER, ownerId: { in: allIds } },
            orderBy: [{ ownerId: 'asc' }, { sort: 'asc' }, { id: 'asc' }],
            select: { id: true, ownerId: true, path: true, sort: true },
          })
        : Promise.resolve([]),
    ]);

    const photosMap = new Map<number, Array<{ id: number; path: string; sort: number }>>();
    for (const p of photos) {
      const list = photosMap.get(p.ownerId) ?? [];
      list.push({ id: p.id, path: p.path, sort: p.sort });
      photosMap.set(p.ownerId, list);
    }

    const enrich = (t: (typeof active)[number]) => {
      const subtasks = subsMap.get(t.id) ?? [];
      return {
        ...t,
        spent: Number(t.spent),
        subtasks,
        total_count: subtasks.length,
        done_count: subtasks.filter((s) => s.done).length,
        photos: photosMap.get(t.id) ?? [],
      };
    };

    const activeOut = sortActive(active.map((t) => ({ ...t, progress: Number(t.progress) })), sort).map(enrich);
    const archiveOut = sortArchive(archive).map(enrich);

    res.json({ active: activeOut, archive: archiveOut, sort });
  } catch (err) {
    console.error('Council tasks error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** GET /api/council/tasks/:id — одна задача с подзадачами и фото. */
router.get('/tasks/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const task = await db.councilTask.findUnique({ where: { id } });
    if (!task) return httpError(res, 404, 'Задача не найдена');

    const [subtasks, photos] = await Promise.all([
      db.councilSubtask.findMany({
        where: { taskId: id },
        orderBy: [{ position: 'asc' }, { id: 'asc' }],
      }),
      db.image.findMany({
        where: { ownerType: PHOTO_OWNER, ownerId: id },
        orderBy: [{ sort: 'asc' }, { id: 'asc' }],
        select: { id: true, path: true, sort: true },
      }),
    ]);

    res.json({
      ...task,
      spent: Number(task.spent),
      subtasks,
      total_count: subtasks.length,
      done_count: subtasks.filter((s) => s.done).length,
      photos,
    });
  } catch (err) {
    console.error('Council task show error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * Проверка расходной части формы — порт expenseError: сумма без статьи не
 * попадёт в бюджет, а без выбора предоплата/постоплата непонятно, когда
 * просить деньги у казначея.
 */
async function expenseError(post: Record<string, unknown>): Promise<string | null> {
  const spent = parseAmount(post.spent);
  if (spent <= 0) return null;

  const categoryId = await pickCategory(post.expense_category_id, 'expense');
  if (categoryId === null) {
    return 'К сумме расходов нужна статья расхода — иначе расход не попадёт в бюджет.';
  }
  // У задачи с предоплатой поле в форме правки заблокировано и не приходит —
  // требуем его только когда оно вообще пришло.
  if ('expense_timing' in post && !EXPENSE_TIMINGS.includes(post.expense_timing as never)) {
    return 'Выберите, когда нужны деньги: предоплата или постоплата.';
  }
  return null;
}

/** POST /api/council/tasks — создать задачу (порт TaskController::create). */
router.post('/tasks', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const body = (req.body ?? {}) as Record<string, unknown>;
    const title = String(body.title ?? '').trim();
    if (title === '') return httpError(res, 400, 'Название задачи не может быть пустым.');

    const expErr = await expenseError(body);
    if (expErr) return httpError(res, 400, expErr);

    // Автор — кто вошёл: из формы не берём, иначе подделаешь.
    const author = await councilName(req.council!.memberId);
    const description = String(body.description ?? '').trim();

    const task = await db.councilTask.create({
      data: {
        title: title.slice(0, 300),
        description: description !== '' ? description : null,
        author,
        assignee: String(body.assignee ?? '').trim(),
        priority: pickPriority(body.priority),
        dueDate: pickDate(body.due_date),
      },
    });

    const status = pickStatus(body.status);
    const categoryId = await pickCategory(body.expense_category_id, 'expense');
    await db.councilTask.update({
      where: { id: task.id },
      data: {
        ...(status ? { status } : {}),
        ...(status === 'выполнена' ? { progress: 100, completedAt: new Date() } : {}),
        ...('spent' in body ? { spent: parseAmount(body.spent) } : {}),
        expenseCategoryId: categoryId,
        expenseTiming: pickTiming(body.expense_timing),
      },
    });

    await syncExpense(task.id);
    await requestExpenseIfNeeded(task.id);

    const fresh = await db.councilTask.findUnique({ where: { id: task.id } });
    res.status(201).json(fresh);
  } catch (err) {
    console.error('Council task create error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/tasks/:id — правка задачи (порт TaskController::update). */
router.post('/tasks/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const body = (req.body ?? {}) as Record<string, unknown>;
    const task = await db.councilTask.findUnique({ where: { id } });
    if (!task) return httpError(res, 404, 'Задача не найдена');

    // Статью в форме правки могли не прислать — считаем по текущей задаче.
    const expErr = await expenseError({
      ...body,
      expense_category_id: 'expense_category_id' in body ? body.expense_category_id : task.expenseCategoryId,
    });
    if (expErr) return httpError(res, 400, expErr);

    const data: Record<string, unknown> = {};
    if ('title' in body) {
      const t = String(body.title ?? '').trim().slice(0, 300);
      data.title = t !== '' ? t : 'Без названия';
    }
    if ('assignee' in body) data.assignee = String(body.assignee ?? '').trim();
    if ('description' in body) data.description = String(body.description ?? '').trim() || null;
    if ('contacts' in body) data.contacts = String(body.contacts ?? '').trim() || null;
    if ('links' in body) data.links = String(body.links ?? '').trim() || null;
    if ('progress' in body) data.progress = Math.max(0, Math.min(100, Number(body.progress) || 0));
    if ('spent' in body) data.spent = parseAmount(body.spent);
    if ('due_date' in body) data.dueDate = pickDate(body.due_date);

    const priority = pickPriority(body.priority);
    if ('priority' in body) data.priority = priority;

    const status = pickStatus(body.status);
    if (status) {
      data.status = status;
      if (status === 'выполнена' && !('progress' in body)) {
        data.progress = 100;
        data.completedAt = new Date();
      }
      if (status !== 'выполнена') data.completedAt = null;
    }

    if ('expense_category_id' in body) {
      data.expenseCategoryId = await pickCategory(body.expense_category_id, 'expense');
    }
    // Предоплату назад не отыграть: деньги у казначея уже запрошены.
    if ('expense_timing' in body && task.expenseTiming !== 'pre') {
      data.expenseTiming = pickTiming(body.expense_timing);
    }

    await db.councilTask.update({ where: { id }, data });
    await syncExpense(id);
    await requestExpenseIfNeeded(id);

    const fresh = await db.councilTask.findUnique({ where: { id } });
    res.json(fresh);
  } catch (err) {
    console.error('Council task update error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/tasks/:id/take — закрепить задачу за собой. */
router.post('/tasks/:id/take', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const task = await db.councilTask.findUnique({ where: { id } });
    if (!task) return httpError(res, 404, 'Задача не найдена');

    const me = await councilName(req.council!.memberId);
    await db.councilTask.update({
      where: { id },
      data: {
        assignee: me,
        ...(task.status === 'новая' ? { status: 'в работе' } : {}),
      },
    });
    res.json({ ok: true, assignee: me });
  } catch (err) {
    console.error('Council task take error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/tasks/:id/done — в архив выполненных. */
router.post('/tasks/:id/done', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    if (!(await db.councilTask.findUnique({ where: { id }, select: { id: true } }))) {
      return httpError(res, 404, 'Задача не найдена');
    }
    await db.councilTask.update({
      where: { id },
      data: { status: 'выполнена', progress: 100, completedAt: new Date() },
    });
    await syncExpense(id);
    await requestExpenseIfNeeded(id);
    res.json({ ok: true });
  } catch (err) {
    console.error('Council task done error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/tasks/:id/reopen — вернуть в работу (прогресс 50, как в легаси). */
router.post('/tasks/:id/reopen', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    if (!(await db.councilTask.findUnique({ where: { id }, select: { id: true } }))) {
      return httpError(res, 404, 'Задача не найдена');
    }
    await db.councilTask.update({
      where: { id },
      data: { status: 'в работе', progress: 50, completedAt: null },
    });
    await syncExpense(id);
    res.json({ ok: true });
  } catch (err) {
    console.error('Council task reopen error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * DELETE /api/council/tasks/:id — удалить задачу.
 * Порядок как в легаси: отменить висящий запрос расхода, снять фото, убрать
 * операцию бюджета, созданную из задачи, и только потом саму задачу.
 */
router.delete('/tasks/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    if (!(await db.councilTask.findUnique({ where: { id }, select: { id: true } }))) {
      return httpError(res, 404, 'Задача не найдена');
    }
    await db.$transaction([
      db.image.deleteMany({ where: { ownerType: PHOTO_OWNER, ownerId: id } }),
      db.councilLedgerEntry.deleteMany({ where: { sourceTaskId: id } }),
      db.councilTask.delete({ where: { id } }),
    ]);
    res.json({ ok: true });
  } catch (err) {
    console.error('Council task delete error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

// ─────────────────────────── Подзадачи ───────────────────────────

/** POST /api/council/tasks/:id/subtasks — добавить подзадачу. */
router.post('/tasks/:id/subtasks', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const taskId = Number(req.params.id);
    if (!(await db.councilTask.findUnique({ where: { id: taskId }, select: { id: true } }))) {
      return httpError(res, 404, 'Задача не найдена');
    }
    const title = String(req.body?.title ?? '').trim() || 'Новая подзадача';

    const count = await db.councilSubtask.count({ where: { taskId } });
    const sub = await db.councilSubtask.create({
      data: { taskId, title: title.slice(0, 300), position: count },
    });

    // AJAX-добавление (легаси отдавал HTML-фрагмент): клиент v2 ждёт JSON,
    // страницу не перезагружает — <details> задачи остаётся открытым.
    res.status(201).json(sub);
  } catch (err) {
    console.error('Council subtask add error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/subtasks/:id/toggle — отметить/снять «сделано». */
router.post('/subtasks/:id/toggle', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const sub = await db.councilSubtask.findUnique({ where: { id } });
    if (!sub) return httpError(res, 404, 'Подзадача не найдена');
    const updated = await db.councilSubtask.update({
      where: { id },
      data: { done: !sub.done },
    });
    res.json(updated);
  } catch (err) {
    console.error('Council subtask toggle error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/subtasks/:id — переименовать подзадачу. */
router.post('/subtasks/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const title = String(req.body?.title ?? '').trim();
    if (title === '') return httpError(res, 400, 'Название не может быть пустым.');
    if (!(await db.councilSubtask.findUnique({ where: { id }, select: { id: true } }))) {
      return httpError(res, 404, 'Подзадача не найдена');
    }
    const updated = await db.councilSubtask.update({
      where: { id },
      data: { title: title.slice(0, 300) },
    });
    res.json(updated);
  } catch (err) {
    console.error('Council subtask rename error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** DELETE /api/council/subtasks/:id — удалить подзадачу. */
router.delete('/subtasks/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    await db.councilSubtask.delete({ where: { id } }).catch(() => undefined);
    res.json({ ok: true });
  } catch (err) {
    console.error('Council subtask delete error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

// ─────────────────────────── Фото задач ───────────────────────────

/**
 * DELETE /api/council/tasks/:id/photos/:imgId — убрать одно фото.
 * Файл в Telegram-канале не удаляем (легаси тоже: у «tg:<file_id>» файла на
 * диске нет) — снимаем только запись, чтобы фото пропало из карточки.
 */
router.delete('/tasks/:id/photos/:imgId', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const taskId = Number(req.params.id);
    const imgId = Number(req.params.imgId);
    const img = await db.image.findFirst({
      where: { id: imgId, ownerType: PHOTO_OWNER, ownerId: taskId },
      select: { id: true },
    });
    if (!img) return httpError(res, 404, 'Фото не найдено');
    await db.image.delete({ where: { id: imgId } });
    res.json({ ok: true });
  } catch (err) {
    console.error('Council task photo delete error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** GET /api/council/tasks/photos/limit — лимит фото на задачу (для формы). */
router.get('/tasks-photos-limit', requireCouncil, (_req: AuthRequest, res) => {
  res.json({ maxPhotos: MAX_PHOTOS });
});

export { router as councilTaskRoutes };
