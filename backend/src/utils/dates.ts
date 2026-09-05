function localParts(now: Date, timeZone: string): { year: number; month: number; day: number } {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now);
  const value = (type: Intl.DateTimeFormatPartTypes) =>
    Number(parts.find((part) => part.type === type)?.value ?? 0);
  return { year: value('year'), month: value('month'), day: value('day') };
}

function isoDate(year: number, month: number, day: number): string {
  return `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}-${day
    .toString()
    .padStart(2, '0')}`;
}

export function localIsoDate(now: Date, timeZone: string): string {
  const parts = localParts(now, timeZone);
  return isoDate(parts.year, parts.month, parts.day);
}

export function shiftIsoDate(date: string, days: number): string {
  const shifted = new Date(`${date}T00:00:00.000Z`);
  shifted.setUTCDate(shifted.getUTCDate() + days);
  return shifted.toISOString().slice(0, 10);
}

export function usageRanges(now: Date, timeZone: string) {
  const today = localIsoDate(now, timeZone);
  const date = new Date(`${today}T00:00:00.000Z`);
  const weekday = date.getUTCDay() || 7;
  return {
    today,
    yesterday: shiftIsoDate(today, -1),
    weekFrom: shiftIsoDate(today, 1 - weekday),
    monthFrom: `${today.slice(0, 8)}01`,
  } as const;
}
