/**
 * Отображаемое название поместья — порт residents/src/HouseholdName.php:
 * «Поместье {Фамилия семьи} ({название})». Фамилия берётся у главы (первого
 * жителя по порядку) и приводится к семейной форме (род. падеж мн. ч.).
 * Только представление — данные в БД не меняются.
 */

function surnameToken(fullName: string): string {
  const parts = fullName.trim().split(/\s+/);
  const t0 = parts[0] || '';
  const t1 = parts[1] || '';
  // Обычно фамилия первая, но часть записей в порядке «Имя Фамилия».
  if (t1 !== '' && !looksLikeSurname(t0) && looksLikeSurname(t1)) return t1;
  return t0;
}

function looksLikeSurname(t: string): boolean {
  return /(ов|ев|ёв|ин|ын|ский|цкий|ской|цкой|ская|цкая|ова|ева|ёва|ина|ына|енко|енка|ко|ук|юк|ич|ыч)$/iu.test(
    t,
  );
}

/** Фамилия → семейная форма (родительный мн. ч.). Несклоняемые — как есть. */
export function familyForm(surname: string): string {
  const s = surname.trim();
  if (s === '') return '';
  const rules: Array<[string, string]> = [
    ['ская', 'ских'],
    ['цкая', 'цких'],
    ['ова', 'овых'],
    ['ёва', 'ёвых'],
    ['ева', 'евых'],
    ['ина', 'иных'],
    ['ына', 'ыных'],
    ['ский', 'ских'],
    ['цкий', 'цких'],
    ['ов', 'овых'],
    ['ёв', 'ёвых'],
    ['ев', 'евых'],
    ['ин', 'иных'],
    ['ын', 'ыных'],
  ];
  for (const [suf, nu] of rules) {
    if (s.endsWith(suf)) return s.slice(0, s.length - suf.length) + nu;
  }
  return s;
}

export function householdTitle(estateName: string, headFullName?: string | null): string {
  const estate = (estateName || '').trim();
  const low = estate.toLowerCase();
  // Уже семейная форма («Поместье/Участок/Двор <Фамилия>») — оставляем как есть.
  for (const pfx of ['поместье ', 'участок ', 'двор ']) {
    if (low.startsWith(pfx)) return estate;
  }

  const surname = headFullName && headFullName.trim() !== '' ? familyForm(surnameToken(headFullName)) : '';

  if (surname === '') return estate !== '' ? estate : 'Поместье';
  if (estate === '') return 'Поместье ' + surname;
  return 'Поместье ' + surname + ' (' + estate + ')';
}
