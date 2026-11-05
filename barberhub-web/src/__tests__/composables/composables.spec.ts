import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'

import { ApiError } from '@/api'
import { useCountdown } from '@/composables/useCountdown'
import { useFormErrors } from '@/composables/useFormErrors'

describe('useCountdown', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('counts down each second and formats m:ss', () => {
    const scope = effectScope()
    const countdown = scope.run(() => useCountdown())!

    expect(countdown.active.value).toBe(false)
    countdown.start(125)
    expect(countdown.formatted.value).toBe('2:05')
    expect(countdown.active.value).toBe(true)

    vi.advanceTimersByTime(5000)
    expect(countdown.formatted.value).toBe('2:00')

    vi.advanceTimersByTime(120_000)
    expect(countdown.remaining.value).toBe(0)
    expect(countdown.active.value).toBe(false)
    expect(vi.getTimerCount()).toBe(0)
    scope.stop()
  })

  it('starts from the initial seconds', () => {
    const scope = effectScope()
    const countdown = scope.run(() => useCountdown(65))!
    expect(countdown.formatted.value).toBe('1:05')
    scope.stop()
  })

  it('clears the timer when the scope is disposed', () => {
    const scope = effectScope()
    scope.run(() => useCountdown(30))
    expect(vi.getTimerCount()).toBe(1)

    scope.stop()

    expect(vi.getTimerCount()).toBe(0)
  })

  it('restarting replaces the previous timer', () => {
    const scope = effectScope()
    const countdown = scope.run(() => useCountdown())!
    countdown.start(10)
    countdown.start(20)
    expect(vi.getTimerCount()).toBe(1)
    expect(countdown.remaining.value).toBe(20)
    scope.stop()
  })
})

describe('useFormErrors', () => {
  const map = { correo: 'email', contrasena: 'password' } as const

  it('turns the 422 field errors into per-field messages', () => {
    const errors = useFormErrors<'email' | 'password'>(map)

    errors.setFromApiError(
      new ApiError({
        status: 422,
        errors: {
          correo: ['El correo ya tiene una cuenta.'],
          contrasena: ['Falta una mayúscula.', 'Falta un número.'],
        },
      }),
    )

    expect(errors.fieldErrors.value.email).toBe('El correo ya tiene una cuenta.')
    expect(errors.fieldErrors.value.password).toBe('Falta una mayúscula. Falta un número.')
    expect(errors.generalError.value).toBe('')
    expect(errors.firstField(['password', 'email'])).toBe('password')
  })

  it('exposes a general message for errors without fields', () => {
    const errors = useFormErrors<'email' | 'password'>(map)

    errors.setFromApiError(new ApiError({ status: 500, detail: 'Algo salió mal.' }))

    expect(errors.generalError.value).toBe('Algo salió mal.')
    expect(errors.firstField(['email', 'password'])).toBeUndefined()
  })

  it('a 422 with only unknown fields falls back to the general message', () => {
    const errors = useFormErrors<'email'>({ correo: 'email' })

    errors.setFromApiError(
      new ApiError({ status: 422, detail: 'Datos inválidos.', errors: { otro: ['x'] } }),
    )

    expect(errors.fieldErrors.value).toEqual({})
    expect(errors.generalError.value).toBe('Datos inválidos.')
  })

  it('clearField and clear remove errors', () => {
    const errors = useFormErrors<'email' | 'password'>(map)
    errors.setField('email', 'a')
    errors.setField('password', 'b')

    errors.clearField('email')
    expect(errors.fieldErrors.value).toEqual({ password: 'b' })

    errors.clear()
    expect(errors.fieldErrors.value).toEqual({})
  })
})
