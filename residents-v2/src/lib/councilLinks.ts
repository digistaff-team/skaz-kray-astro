/**
 * Ссылки на мини-приложение Совета — порт BotNotify::deepLink и
 * CouncilDutyNotify::base/link из легаси.
 *
 * deepLink кодирует путь внутри мини-приложения в base64url и кладёт его в
 * ?startapp=: Telegram открывает Web App ровно на этот путь, MAX дописывает
 * параметр к своему «?startapp».
 *
 * Env читается в момент вызова (урок Vercel: module-level значение → undefined).
 */

/** base64url(путь) без padding — порт BotNotify::deepLink. */
export function deepLink(base: string, path: string): string {
  const code = Buffer.from(path, 'utf8').toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
  // MAX отдаёт base с уже вшитым '?startapp' — параметр дописываем через '='.
  return base.endsWith('?startapp')
    ? base + '=' + code
    : base.replace(/\/+$/, '') + '?startapp=' + code;
}

/** База мини-приложения Совета по платформе (дефолты легаси-конфига). */
export function councilAppBase(platform: 'tg' | 'max' = 'tg'): string {
  if (platform === 'max') {
    return process.env.COUNCIL_MAX_APP_LINK || 'https://max.ru/id643900558807_2_bot?startapp';
  }
  return process.env.COUNCIL_APP_LINK || 'https://t.me/SkazKray_bot/sovet';
}

/** Диплинк мини-приложения Совета на путь внутри приложения (по умолчанию /sovet). */
export function councilAppLink(platform: 'tg' | 'max' = 'tg', path = '/sovet'): string {
  return deepLink(councilAppBase(platform), path);
}
