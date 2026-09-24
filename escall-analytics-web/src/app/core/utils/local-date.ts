const pad = (value: number): string => String(value).padStart(2, '0');

export const toLocalIsoDate = (date: Date): string =>
  `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

export const todayLocalIsoDate = (): string => toLocalIsoDate(new Date());

export const currentMonthStartLocalIsoDate = (): string => {
  const now = new Date();

  return toLocalIsoDate(new Date(now.getFullYear(), now.getMonth(), 1));
};
