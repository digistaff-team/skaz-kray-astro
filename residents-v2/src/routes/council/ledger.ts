import { Router } from 'express';
import { AuthRequest, requireCouncil, requireCouncilAdmin } from '../../middleware/auth';
import { db } from '../../lib/db';
import { httpError, councilName, parseAmount, pickDate, LEDGER_KINDS } from '../../lib/councilHelpers';
import { buildLedgerReport } from '../../services/ledgerReport';

const router = Router();

/**
 * Бухгалтерия Совета — порт residents/src/Controller/Council/LedgerController.php.
 *
 * Вводить и править операции может любой вошедший член совета; справочник
 * статей (создать/переименовать/архивировать) — только администратор совета.
 *
 * Чеки расходов (легаси LedgerController::handleReceipt) не портированы: в v2
 * загрузки файлов нет ни в одном разделе — это общий кусок, решать его надо
 * единым механизмом хранения. Просмотр уже загруженных чеков работает: отчёт
 * отдаёт receiptPath, удаление операции снимает записи images.
 */

type Kind = (typeof LEDGER_KINDS)[number];

function pickKind(v: unknown): Kind | null {
  return LEDGER_KINDS.includes(v as Kind) ? (v as Kind) : null;
}

/** Активные статьи нужного вида — для формы ввода операции. */
async function activeCategories(kind: Kind) {
  return db.councilLedgerCategory.findMany({
    where: { kind, isActive: true },
    orderBy: [{ position: 'asc' }, { id: 'asc' }],
    select: { id: true, name: true },
  });
}

/** GET /api/council/ledger?mesyac=YYYY-MM — отчёт + статьи + право на справочник. */
router.get('/ledger', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const ym = typeof req.query.mesyac === 'string' ? req.query.mesyac : null;
    const [report, incomeCats, expenseCats] = await Promise.all([
      buildLedgerReport(ym),
      activeCategories('income'),
      activeCategories('expense'),
    ]);
    res.json({
      report,
      incomeCats,
      expenseCats,
      canManageCategories: req.council!.role === 'admin',
      me: await councilName(req.council!.memberId),
    });
  } catch (err) {
    console.error('Council ledger error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/ledger — добавить операцию.
 * Статья обязана существовать и совпадать по виду с типом операции — иначе
 * расход попадёт в доходную статью и отчёт «поедет».
 */
router.post('/ledger', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const body = (req.body ?? {}) as Record<string, unknown>;
    const kind = pickKind(body.kind);
    const categoryId = Number(body.category_id ?? 0);
    const amount = parseAmount(body.amount);
    const entryDate = pickDate(body.entry_date);
    const note = String(body.note ?? '').trim();

    const category = kind && categoryId > 0
      ? await db.councilLedgerCategory.findUnique({ where: { id: categoryId } })
      : null;
    if (!kind || !category || category.kind !== kind) {
      return httpError(res, 400, 'Выберите статью, соответствующую типу операции.');
    }
    if (amount <= 0) return httpError(res, 400, 'Укажите сумму больше нуля.');
    if (!entryDate) return httpError(res, 400, 'Укажите корректную дату.');

    const entry = await db.councilLedgerEntry.create({
      data: {
        kind,
        categoryId,
        amount,
        entryDate,
        note: note.slice(0, 300),
        author: (await councilName(req.council!.memberId)).slice(0, 160),
      },
    });
    res.status(201).json(entry);
  } catch (err) {
    console.error('Council ledger create error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

// ─────────────────── Справочник статей (только админ) ───────────────────
// Объявлен раньше /ledger/:id — иначе Express примет «categories» за id.

/** GET /api/council/ledger/categories — все статьи, включая архивные. */
router.get('/ledger/categories', requireCouncilAdmin, async (_req: AuthRequest, res) => {
  try {
    const [income, expense] = await Promise.all([
      db.councilLedgerCategory.findMany({
        where: { kind: 'income' },
        orderBy: [{ position: 'asc' }, { id: 'asc' }],
      }),
      db.councilLedgerCategory.findMany({
        where: { kind: 'expense' },
        orderBy: [{ position: 'asc' }, { id: 'asc' }],
      }),
    ]);
    res.json({ income, expense });
  } catch (err) {
    console.error('Council categories error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/ledger/categories — новая статья (в конец своего списка). */
router.post('/ledger/categories', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const body = (req.body ?? {}) as Record<string, unknown>;
    const kind = pickKind(body.kind) ?? 'expense';
    const name = String(body.name ?? '').trim().slice(0, 160);
    if (name === '') return httpError(res, 400, 'Название статьи не может быть пустым.');

    const count = await db.councilLedgerCategory.count({ where: { kind } });
    const category = await db.councilLedgerCategory.create({
      data: { kind, name, position: count },
    });
    res.status(201).json(category);
  } catch (err) {
    console.error('Council category add error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/ledger/categories/:id — переименовать статью. */
router.post('/ledger/categories/:id', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const name = String(req.body?.name ?? '').trim().slice(0, 160);
    if (name === '') return httpError(res, 400, 'Название статьи не может быть пустым.');
    if (!(await db.councilLedgerCategory.findUnique({ where: { id }, select: { id: true } }))) {
      return httpError(res, 404, 'Статья не найдена');
    }
    const updated = await db.councilLedgerCategory.update({ where: { id }, data: { name } });
    res.json(updated);
  } catch (err) {
    console.error('Council category rename error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/ledger/categories/:id/toggle — архив/возврат в выбор.
 * Статьи не удаляются физически: исторические операции не должны потерять имя.
 */
router.post('/ledger/categories/:id/toggle', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const cat = await db.councilLedgerCategory.findUnique({ where: { id } });
    if (!cat) return httpError(res, 404, 'Статья не найдена');
    const updated = await db.councilLedgerCategory.update({
      where: { id },
      data: { isActive: !cat.isActive },
    });
    res.json(updated);
  } catch (err) {
    console.error('Council category toggle error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/ledger/:id — правка операции.
 * Операции из задач не правятся здесь: сумма и статья живут в задаче, иначе
 * задача и бюджет разойдутся (syncExpense перезапишет операцию).
 */
router.post('/ledger/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const body = (req.body ?? {}) as Record<string, unknown>;
    const entry = await db.councilLedgerEntry.findUnique({ where: { id } });
    if (!entry) return httpError(res, 404, 'Операция не найдена');
    if ((entry.sourceTaskId ?? 0) > 0) {
      return httpError(res, 400, 'Эта операция создана из задачи — правьте сумму или статью в разделе «Задачи».');
    }

    const data: Record<string, unknown> = {};
    if ('category_id' in body) {
      const categoryId = Number(body.category_id ?? 0);
      const cat = categoryId > 0
        ? await db.councilLedgerCategory.findUnique({ where: { id: categoryId } })
        : null;
      // Вид операции не меняется — статья только из того же справочника.
      if (cat && cat.kind === entry.kind) data.categoryId = cat.id;
    }
    if ('amount' in body) data.amount = parseAmount(body.amount);
    if ('note' in body) data.note = String(body.note ?? '').trim().slice(0, 300);
    if ('entry_date' in body) {
      const d = pickDate(body.entry_date);
      if (d) data.entryDate = d;
    }

    if (Object.keys(data).length === 0) return res.json(entry);
    const updated = await db.councilLedgerEntry.update({ where: { id }, data });
    res.json(updated);
  } catch (err) {
    console.error('Council ledger update error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** DELETE /api/council/ledger/:id — удалить операцию вместе с чеками. */
router.delete('/ledger/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const entry = await db.councilLedgerEntry.findUnique({ where: { id } });
    if (!entry) return httpError(res, 404, 'Операция не найдена');
    if ((entry.sourceTaskId ?? 0) > 0) {
      return httpError(res, 400, 'Операция из задачи убирается обнулением суммы или удалением самой задачи.');
    }
    await db.$transaction([
      db.image.deleteMany({ where: { ownerType: 'expense', ownerId: id } }),
      db.councilLedgerEntry.delete({ where: { id } }),
    ]);
    res.json({ ok: true });
  } catch (err) {
    console.error('Council ledger delete error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

export { router as councilLedgerRoutes };
