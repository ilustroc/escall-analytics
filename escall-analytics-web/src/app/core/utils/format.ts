const numberFormatter = new Intl.NumberFormat('en-US');
const decimalFormatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const formatNumber = (value: number) => numberFormatter.format(value);
export const formatDecimal = (value: number) => decimalFormatter.format(value);
export const formatMoney = (value: number, decimals = false) => `S/ ${decimals ? formatDecimal(value) : formatNumber(Math.round(value))}`;
export const formatPercent = (value: number) => `${formatDecimal(value)}%`;
export function formatDate(value: string | null): string {
  if (!value) return '—';
  const [year, month, day] = value.slice(0, 10).split('-');
  return `${day}/${month}/${year}`;
}
export const formatTime = (value: string | null): string => value || '—';
export const shortAdvisor = (value: string) => value.split(' ').map(part => part.charAt(0) + part.slice(1).toLowerCase()).join(' ');
