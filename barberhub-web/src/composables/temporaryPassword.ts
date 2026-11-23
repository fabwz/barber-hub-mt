import { meetsPasswordPolicy } from '@/composables/passwordPolicy'

// The temporary password of a new collaborator (UP-04, SE-07). The owner never types it: it is made here, with the
// random numbers of the browser, shown once and sent to the API only to create the account. The API never returns it.

// Without the letters and digits that look alike (I, l, 1, O, 0), so it can be read aloud or copied by hand.
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
const LOWER = 'abcdefghijkmnopqrstuvwxyz'
const DIGITS = '23456789'
const SYMBOLS = '!#$%&*+-=?@'
const ALL = UPPER + LOWER + DIGITS + SYMBOLS

export const TEMPORARY_PASSWORD_LENGTH = 12

/** A number from 0 to `limit - 1` without bias: values that would favor the first characters are drawn again. */
function randomBelow(limit: number): number {
  const range = 0x1_0000_0000
  const ceiling = range - (range % limit)
  const buffer = new Uint32Array(1)
  do {
    crypto.getRandomValues(buffer)
  } while (buffer[0]! >= ceiling)
  return buffer[0]! % limit
}

function pick(characters: string): string {
  return characters.charAt(randomBelow(characters.length))
}

/** One character of each kind, the rest from all of them, in a random order (Fisher-Yates with the same source). */
export function generateTemporaryPassword(length: number = TEMPORARY_PASSWORD_LENGTH): string {
  const characters = [pick(UPPER), pick(LOWER), pick(DIGITS), pick(SYMBOLS)]
  while (characters.length < Math.max(length, 8)) characters.push(pick(ALL))
  for (let index = characters.length - 1; index > 0; index--) {
    const other = randomBelow(index + 1)
    const swap = characters[index]!
    characters[index] = characters[other]!
    characters[other] = swap
  }
  const password = characters.join('')
  // Guaranteed by construction; the check keeps the promise if the sets above ever change.
  if (!meetsPasswordPolicy(password)) return generateTemporaryPassword(length)
  return password
}
