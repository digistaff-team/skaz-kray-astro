import { Router } from 'express';
import crypto from 'crypto';
import { AuthRequest, requireCouncilAdmin } from '../../middleware/auth';
import { db } from '../../lib/db';
import { httpError, publicMember } from '../../lib/councilHelpers';
import { hashPassword } from '../../lib/crypto';
import { setDutyChair } from '../../lib/councilDuty';
import { sendMail } from '../../lib/mailer';

const router = Router();

/**
 * Аккаунты членов Совета — порт residents/src/Controller/Council/AdminController.php.
 * Всё под requireCouncilAdmin: панель управления видит только администратор.
 *
 * Модель «только по приглашению»: админ заводит запись по email, система
 * генерирует пароль (показывается админу один раз и уходит письмом). Дальше
 * член совета меняет пароль сам через POST /api/council/password.
 */

/** Срок жизни кода привязки Telegram — как CLAIM_CODE_TTL в легаси. */
const CLAIM_CODE_TTL_MS = 7 * 86400 * 1000;

/** Алфавит без похожих символов (0/O, 1/I/L): код диктуют голосом. */
const CLAIM_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** 10 hex-символов (~40 бит) — удобно продиктовать по телефону. */
function generatePassword(): string {
  return crypto.randomBytes(5).toString('hex');
}

function generateClaimCode(): string {
  let code = '';
  for (let i = 0; i < 8; i++) {
    code += CLAIM_CODE_ALPHABET[crypto.randomInt(CLAIM_CODE_ALPHABET.length)];
  }
  return code;
}

function hashClaimCode(code: string): string {
  return crypto.createHash('sha256').update(code).digest('hex');
}

/** Публичное представление участника: хеши наружу не отдаём. */
function toPublic(m: {
  id: number; email: string; name: string; surname: string; status: string; role: string;
  telegramId: bigint | null; maxUserId: bigint | null; isDutyChair: boolean;
  createdAt: Date; passwordHash?: string; claimCodeHash?: string | null;
}) {
  return {
    ...publicMember(m),
    hasClaimCode: Boolean(m.claimCodeHash),
    telegramLinked: m.telegramId !== null,
    maxLinked: m.maxUserId !== null,
  };
}

/** GET /api/council/admin/members — список аккаунтов совета. */
router.get('/admin/members', requireCouncilAdmin, async (_req: AuthRequest, res) => {
  try {
    const members = await db.councilMember.findMany({ orderBy: { createdAt: 'asc' } });
    res.json({ members: members.map(toPublic) });
  } catch (err) {
    console.error('Council members list error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/admin/members — завести аккаунт по приглашению.
 * Пароль возвращается в ответе один раз: админ передаёт его лично.
 */
router.post('/admin/members', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const body = (req.body ?? {}) as Record<string, unknown>;
    const email = String(body.email ?? '').trim();
    const name = String(body.name ?? '').trim();
    const surname = String(body.surname ?? '').trim().slice(0, 120);
    const role = String(body.role ?? '') === 'admin' ? 'admin' : 'member';

    const fields: Record<string, string> = {};
    if (!EMAIL_RE.test(email)) fields.email = 'Укажите корректный email.';
    if (name.length < 2 || name.length > 160) fields.name = 'Имя: 2–160 символов.';
    if (Object.keys(fields).length > 0) {
      return res.status(400).json({ error: 'Проверьте поля формы.', fields });
    }
    if (await db.councilMember.findUnique({ where: { email } })) {
      return res.status(400).json({
        error: 'Такой член совета уже есть.',
        fields: { email: 'Такой член совета уже есть.' },
      });
    }

    const password = generatePassword();
    const member = await db.councilMember.create({
      data: { email, name, surname, role, passwordHash: hashPassword(password) },
    });

    // Письмо не должно задерживать ответ: админ и так видит пароль на экране.
    const base = (process.env.BASE_URL || 'https://skaz-kray.ru').replace(/\/+$/, '');
    void sendMail(
      email,
      'Доступ в раздел Попечительского совета — Сказочный Край',
      `<p>Здравствуйте, ${name}!</p>
       <p>Вам открыт доступ в раздел Попечительского совета на сайте.</p>
       <p>Вход: <a href="${base}/sovet/vhod">${base}/sovet/vhod</a><br>
       Email: ${email}<br>Временный пароль: <b>${password}</b></p>
       <p>После входа смените пароль в разделе «Пароль». Если письмо пришло по ошибке — просто игнорируйте его.</p>`,
    ).catch((e) => console.error('Council invite mail error:', e));

    res.status(201).json({ member: toPublic(member), password });
  } catch (err) {
    console.error('Council member add error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/admin/members/:id/password — сбросить пароль вручную. */
router.post('/admin/members/:id/password', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const member = await db.councilMember.findUnique({ where: { id } });
    if (!member) return httpError(res, 404, 'Член совета не найден');

    const password = generatePassword();
    await db.councilMember.update({ where: { id }, data: { passwordHash: hashPassword(password) } });
    res.json({ ok: true, name: member.name, password });
  } catch (err) {
    console.error('Council member reset error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/admin/members/:id/status — блокировка/разблокировка.
 * Себя блокировать нельзя: можно потерять последний доступ администратора.
 */
router.post('/admin/members/:id/status', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    if (id === req.council!.memberId) {
      return httpError(res, 400, 'Нельзя заблокировать собственный аккаунт.');
    }
    const member = await db.councilMember.findUnique({ where: { id } });
    if (!member) return httpError(res, 404, 'Член совета не найден');

    const status = member.status === 'active' ? 'blocked' : 'active';
    const updated = await db.councilMember.update({ where: { id }, data: { status } });
    res.json({ ok: true, status: updated.status });
  } catch (err) {
    console.error('Council member status error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/admin/members/:id/duty — назначить Дежурного председателя.
 * member_id = 0 снимает дежурство со всех (роль переходящая и единоличная).
 */
router.post('/admin/members/:id/duty', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    if (id === 0) {
      await setDutyChair(0);
      return res.json({ ok: true, dutyChair: null });
    }
    const member = await db.councilMember.findUnique({ where: { id } });
    if (!member) return httpError(res, 404, 'Член совета не найден');
    if (member.status !== 'active') {
      return httpError(res, 400, 'Нельзя назначить дежурным заблокированный аккаунт.');
    }
    await setDutyChair(id);
    // Подпись в карточке собрания держим в синхроне — её показывает главная /sovet/.
    await db.councilMeeting.update({ where: { id: 1 }, data: { dutyChair: member.name } })
      .catch(() => undefined);
    res.json({ ok: true, dutyChair: member.name });
  } catch (err) {
    console.error('Council member duty error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/admin/members/:id/unbind-telegram — снять ошибочную привязку. */
router.post('/admin/members/:id/unbind-telegram', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const member = await db.councilMember.findUnique({ where: { id } });
    if (!member) return httpError(res, 404, 'Член совета не найден');

    await db.councilMember.update({ where: { id }, data: { telegramId: null } });
    res.json({ ok: true, name: member.name });
  } catch (err) {
    console.error('Council member unbind error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/admin/members/:id/claim-code — выдать код привязки Telegram.
 * В БД уходит только sha256: сам код видит лишь админ и передаёт лично.
 * Без кода по одной фамилии войти нельзя — состав совета не секрет.
 */
router.post('/admin/members/:id/claim-code', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const member = await db.councilMember.findUnique({ where: { id } });
    if (!member) return httpError(res, 404, 'Член совета не найден');
    if (member.telegramId !== null) {
      return httpError(res, 400, 'Telegram уже привязан. Чтобы перепривязать, сначала отвяжите его.');
    }
    if (member.status !== 'active') {
      return httpError(res, 400, 'Аккаунт заблокирован — сначала разблокируйте его.');
    }

    const raw = generateClaimCode();
    await db.councilMember.update({
      where: { id },
      data: {
        claimCodeHash: hashClaimCode(raw),
        claimCodeExpires: new Date(Date.now() + CLAIM_CODE_TTL_MS),
      },
    });
    // Показываем «ABCD-EFGH»: так код проще продиктовать, ввод его нормализует.
    res.json({ ok: true, name: member.name, code: `${raw.slice(0, 4)}-${raw.slice(4)}` });
  } catch (err) {
    console.error('Council member claim code error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

export { router as councilAdminRoutes };
