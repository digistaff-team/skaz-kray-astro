import { db } from '../lib/db';

/**
 * «Мои дела» — всё, что ждёт действия жителя, собранное из текущих статусов.
 * Порт residents/src/Service/MyTasks.php.
 *
 * Дело = { kind, title, detail, link, date, urgent }. date — момент, с которого
 * дело ждёт; по нему сортируем.
 */

// ═══════════════════════════════════════════════════════════════════════
// Форматирование (паритет с helpers.php / ru_date)
// ═══════════════════════════════════════════════════════════════════════

function ruDate(s: string): string {
  if (!s) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  if (!m) return s;
  const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
  return `${Number(m[3])} ${months[Number(m[1]) - 1]} ${m[0].split('-')[0]}`;
}

function withDue(who: string, due: string | null | undefined): string {
  if (due == null || due === '') return who;
  return `${who} · ${ruDate(due)}`;
}

function rejectReason(rejectReason: string | null | undefined): string {
  const r = (rejectReason ?? '').trim();
  return r !== '' && r !== 'Без указания причины' ? `Причина: ${r}` : 'Исправьте и отправьте снова';
}

function pluralRu(n: number, one: string, few: string, many: string): string {
  const n10 = n % 100, n1 = n % 10;
  if (n10 > 10 && n10 < 20) return many;
  if (n1 > 1 && n1 < 5) return few;
  if (n1 === 1) return one;
  return many;
}

function deliveryKindLabel(kind: string): string {
  return kind === 'pickup' ? 'Забрать' : 'Купить';
}

function buyMoneyRaw(d: string | number | null | undefined): string {
  if (d == null) return '';
  const n = typeof d === 'string' ? parseFloat(d) : d;
  return n.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 1 }).replace(/\s0+$/, '');
}

// ═══════════════════════════════════════════════════════════════════════
// Сбор одного источника дел
// ═══════════════════════════════════════════════════════════════════════

interface Task {
  kind: string; title: string; detail: string; link: string; date: string; urgent: boolean;
}

function task(kind: string, title: string, detail: string, link: string, date: string, urgent = false): Task {
  return { kind, title, detail, link, date, urgent };
}

/**
 * Выключенный раздел не опрашивается (sectionsKey = null → опрашиваем).
 * Сбой источника — в лог, остальные дела показываем.
 */
async function collect(
  section: string | null,
  fn: () => Promise<Task[]>,
): Promise<Task[]> {
  try {
    if (section !== null) {
      const row = await db.appSection.findUnique({ where: { sectionKey: section } });
      if (row && row.enabled === false) return [];
    }
    return await fn();
  } catch (e) {
    console.error(`MyTasks (${section ?? 'moderation'}): ${(e as Error).message}`);
    return [];
  }
}

// ═══════════════════════════════════════════════════════════════════════
// Источники
// ═══════════════════════════════════════════════════════════════════════

async function toolRequests(familyId: number): Promise<Task[]> {
  const loans = await db.toolLoan.findMany({
    where: { tool: { familyId }, status: 'requested' },
    select: { tool: { select: { name: true } }, borrower: { select: { name: true } },
              dueDate: true, requestedAt: true },
  });
  return loans.map(l => task('tool_request', `Заявка на «${l.tool.name}»`,
    withDue(l.borrower.name, l.dueDate?.toISOString().split('T')[0]),
    '/poselenie/instrumenty/moi', l.requestedAt.toISOString()));
}

async function bookRequests(familyId: number): Promise<Task[]> {
  const loans = await db.bookLoan.findMany({
    where: { book: { familyId }, status: 'requested' },
    select: { book: { select: { title: true } }, borrower: { select: { name: true } },
              dueDate: true, requestedAt: true },
  });
  return loans.map(l => task('book_request', `Бронь книги «${l.book.title}»`,
    withDue(l.borrower.name, l.dueDate?.toISOString().split('T')[0]),
    '/poselenie/knigi/moi', l.requestedAt.toISOString()));
}

async function tripBookings(familyId: number, today: string): Promise<Task[]> {
  const bookings = await db.tripBooking.findMany({
    where: { trip: { driverId: familyId, status: 'active', tripDate: { gte: new Date(today) } }, status: 'requested' },
    select: { trip: { select: { origin: true, destination: true, tripDate: true } },
              passenger: { select: { name: true } }, seats: true, createdAt: true },
  });
  return bookings.map(b => {
    const td = b.trip.tripDate?.toISOString().split('T')[0] ?? '';
    return task('trip_booking', `Бронь в поездке ${b.trip.origin} → ${b.trip.destination}`,
      `${b.passenger.name}, мест: ${b.seats} · ${ruDate(td)}`,
      '/poselenie/poezdki/moi', b.createdAt.toISOString());
  });
}

async function rejectedProducts(familyId: number): Promise<Task[]> {
  const products = await db.product.findMany({
    where: { familyId, status: 'rejected' },
    select: { id: true, title: true, rejectReason: true, updatedAt: true },
  });
  return products.map(p => task('product_rejected', `Объявление «${p.title}» не прошло проверку`,
    rejectReason(p.rejectReason), `/poselenie/yarmarka/${p.id}/redaktirovat`, p.updatedAt.toISOString()));
}

async function rejectedDiary(familyId: number): Promise<Task[]> {
  const entries = await db.diaryEntry.findMany({
    where: { familyId, status: 'rejected' },
    select: { id: true, title: true, rejectReason: true, updatedAt: true },
  });
  return entries.map(e => task('diary_rejected', `Запись «${e.title}» не прошла проверку`,
    rejectReason(e.rejectReason), `/poselenie/dnevnik/${e.id}/redaktirovat`, e.updatedAt.toISOString()));
}

async function overdueTools(familyId: number, today: string): Promise<Task[]> {
  const loans = await db.toolLoan.findMany({
    where: { borrowerId: familyId, status: 'on_loan', dueDate: { not: null, lte: new Date(today) } },
    select: { tool: { select: { name: true, familyId: true } }, dueDate: true },
  });
  const out: Task[] = [];
  for (const l of loans) {
    const owner = await db.family.findUnique({ where: { id: l.tool.familyId }, select: { name: true } });
    out.push(task('tool_overdue', `Пора вернуть «${l.tool.name}»`,
      `${owner?.name ?? ''} · срок был ${ruDate(l.dueDate?.toISOString().split('T')[0] ?? '')}`,
      '/poselenie/instrumenty/moi', l.dueDate?.toISOString().split('T')[0] ?? '', true));
  }
  return out;
}

async function overdueBooks(familyId: number, today: string): Promise<Task[]> {
  const loans = await db.bookLoan.findMany({
    where: { borrowerId: familyId, status: 'on_loan', dueDate: { not: null, lte: new Date(today) } },
    select: { book: { select: { title: true, familyId: true } }, dueDate: true },
  });
  const out: Task[] = [];
  for (const l of loans) {
    const owner = await db.family.findUnique({ where: { id: l.book.familyId }, select: { name: true } });
    out.push(task('book_overdue', `Пора вернуть книгу «${l.book.title}»`,
      `${owner?.name ?? ''} · срок был ${ruDate(l.dueDate?.toISOString().split('T')[0] ?? '')}`,
      '/poselenie/knigi/moi', l.dueDate?.toISOString().split('T')[0] ?? '', true));
  }
  return out;
}

async function purchasesAsOrganizer(familyId: number, today: string): Promise<Task[]> {
  const purchases = await db.purchase.findMany({
    where: { organizerId: familyId, status: { in: ['collecting', 'arrived'] } },
    select: { id: true, title: true, status: true, deadline: true, updatedAt: true,
              orders: { select: { paidAt: true, qty: true } } },
  });
  const out: Task[] = [];
  for (const p of purchases) {
    const unpaid = p.orders.filter(o => !o.paidAt).length;
    const link = `/poselenie/zakupki/${p.id}`;
    const dl = p.deadline?.toISOString().split('T')[0] ?? '';

    if (p.status === 'collecting' && dl !== '' && dl < today) {
      out.push(task('purchase_deadline', `Сбор по закупке «${p.title}» закончился`,
        'Оформите заказ или продлите срок · срок был ' + ruDate(p.deadline?.toISOString() ?? ''), link, dl));
    }
    if (p.status === 'arrived' && unpaid > 0) {
      out.push(task('purchase_unpaid', `Отметьте оплату: «${p.title}»`,
        `Не отмечено у ${pluralRu(unpaid, 'участника', 'участников', 'участников')}`,
        link, p.updatedAt.toISOString()));
    }
  }
  return out;
}

async function purchasesAsParticipant(familyId: number): Promise<Task[]> {
  const orders = await db.purchaseOrder.findMany({
    where: { familyId, purchase: { status: 'arrived' } },
    select: { purchase: { select: { id: true, title: true, pickup: true, updatedAt: true,
              organizer: { select: { name: true } } } }, paidAt: true },
  });
  return orders
    .filter(o => !o.paidAt)
    .map(o => {
      const p = o.purchase;
      const pickup = (p.pickup ?? '').trim();
      return task('purchase_pickup', `Привезли «${p.title}» — заберите`,
        pickup ? `Где забрать: ${pickup}` : `Уточните у организатора: ${p.organizer.name}`,
        `/poselenie/zakupki/${p.id}`, p.updatedAt.toISOString());
    });
}

async function deliveryRequests(familyId: number, today: string): Promise<Task[]> {
  const deliveries = await db.delivery.findMany({
    where: {
      carrierId: familyId,
      status: { in: ['requested', 'open'] },
      trip: { status: 'active', tripDate: { gte: new Date(today) } },
    },
    select: { kind: true, place: true, needBy: true,
              requester: { select: { name: true } }, createdAt: true },
  });
  return deliveries.map(d => task('delivery_request',
    `Просьба привезти: ${deliveryKindLabel(d.kind)} · ${d.place}`,
    d.needBy ? `${d.requester.name} · к ${ruDate(d.needBy.toISOString())}` : d.requester.name,
    '/poselenie/dostavka/moi', d.createdAt.toISOString()));
}

async function deliveryConfirms(familyId: number): Promise<Task[]> {
  const deliveries = await db.delivery.findMany({
    where: { requesterId: familyId, status: 'delivered' },
    select: { id: true, kind: true, place: true, carrier: { select: { name: true } },
              receiptSum: true, deliveredAt: true, createdAt: true },
  });
  return deliveries.map(d => {
    const sum = d.receiptSum != null ? `по чеку ${buyMoneyRaw(Number(d.receiptSum))} ₽` : '';
    const parts = [d.carrier?.name ?? '', sum].filter(Boolean).join(' · ');
    return task('delivery_confirm',
      `Подтвердите получение: ${deliveryKindLabel(d.kind)} · ${d.place}`,
      parts, `/poselenie/dostavka/${d.id}`,
      d.deliveredAt?.toISOString() ?? d.createdAt.toISOString());
  });
}

async function moderationQueue(familyId: number): Promise<Task[]> {
  const [pendingFamilies, pendingDiary, pendingProducts] = await Promise.all([
    db.family.count({ where: { status: 'pending' } }),
    db.diaryEntry.count({ where: { status: 'pending' } }),
    db.product.count({ where: { status: 'pending' } }),
  ]);
  const parts: string[] = [];
  if (pendingFamilies) parts.push(`заявки на вход: ${pendingFamilies}`);
  if (pendingDiary) parts.push(`записи: ${pendingDiary}`);
  if (pendingProducts) parts.push(`объявления: ${pendingProducts}`);
  const total = pendingFamilies + pendingDiary + pendingProducts;
  return total > 0 ? [task('moderation', `На проверке: ${total}`, parts.join(' · '), '/poselenie/moderation', '')] : [];
}

// ═══════════════════════════════════════════════════════════════════════
// Сводка
// ═══════════════════════════════════════════════════════════════════════

export async function buildMyTasks(familyId: number, isEditor: boolean, today: string): Promise<Task[]> {
  const tasks: Task[] = [
    ...(await collect('instrumenty', () => toolRequests(familyId))),
    ...(await collect('knigi', () => bookRequests(familyId))),
    ...(await collect('poezdki', () => tripBookings(familyId, today))),
    ...(await collect('yarmarka', () => rejectedProducts(familyId))),
    ...(await collect('dnevniki', () => rejectedDiary(familyId))),
    ...(await collect('instrumenty', () => overdueTools(familyId, today))),
    ...(await collect('knigi', () => overdueBooks(familyId, today))),
    ...(await collect('zakupki', () => purchasesAsOrganizer(familyId, today))),
    ...(await collect('zakupki', () => purchasesAsParticipant(familyId))),
    ...(await collect('dostavka', () => deliveryRequests(familyId, today))),
    ...(await collect('dostavka', () => deliveryConfirms(familyId))),
  ];

  if (isEditor) {
    tasks.push(...(await collect(null, () => moderationQueue(familyId))));
  }

  // Срочные — сверху, дальше кто дольше ждёт, модерация — последней
  const rank = (t: Task): number => t.kind === 'moderation' ? 2 : (t.urgent ? 0 : 1);
  tasks.sort((a, b) => {
    if (rank(a) !== rank(b)) return rank(a) - rank(b);
    return a.date < b.date ? -1 : a.date > b.date ? 1 : 0;
  });

  return tasks;
}
