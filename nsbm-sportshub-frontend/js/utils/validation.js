export const isEmail = value => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value));
export function validateRange(start, end, { future = false, maxHours = Infinity } = {}) {
  const from = new Date(start).getTime(), to = new Date(end).getTime();
  if (!Number.isFinite(from) || !Number.isFinite(to)) return 'Choose a valid start and end time.';
  if (future && from <= Date.now()) return 'The start time must be in the future.';
  if (to <= from) return 'The end must be after the start.';
  if (to - from > maxHours * 3600000) return `Bookings may be at most ${maxHours} hours.`;
  return '';
}
