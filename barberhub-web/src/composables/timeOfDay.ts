/** Hours are written as HH:MM, 24 hours, Costa Rica time (HO-03). The API has the last word. */

export const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/

export function isValidTime(value: string): boolean {
  return TIME_PATTERN.test(value)
}

/** Digits only, with the colon added by itself after the hour: «0900» becomes «09:00». */
export function cleanTime(value: string): string {
  const digits = value.replace(/\D/g, '').slice(0, 4)
  return digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits
}
