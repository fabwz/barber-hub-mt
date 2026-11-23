import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { ApiError } from '@/api'
import { useAccountStore } from '@/stores/account'
import { useSessionStore } from '@/stores/session'
import { ACCOUNT, accountHandler, signIn } from '../helpers/account'
import { jsonResponse, mockFetch, problem } from '../helpers/http'

beforeEach(() => {
  sessionStorage.clear()
  setActivePinia(createPinia())
})
afterEach(() => vi.unstubAllGlobals())

describe('account store', () => {
  it('starts without data and without knowing about the temporary password', () => {
    const account = useAccountStore()

    expect(account.status).toBe('idle')
    expect(account.profile).toBeNull()
    expect(account.temporaryPassword).toBeNull()
  })

  it('load reads GET /cuenta: the profile and the temporary password flag', async () => {
    const { calls } = accountHandler({ account: { ...ACCOUNT, contrasena_temporal: true } })
    const account = useAccountStore()

    await account.load()

    expect(calls.map((call) => call.route)).toEqual(['GET /api/v1/cuenta'])
    expect(account.status).toBe('ready')
    expect(account.profile).toEqual({
      name: 'Laura Brenes',
      phone: '88881234',
      email: 'laura.brenes@correo.cr',
    })
    expect(account.temporaryPassword).toBe(true)
  })

  it('a staff account has no phone yet: it is an empty text', async () => {
    accountHandler({ account: { ...ACCOUNT, telefono: null as unknown as string } })
    const account = useAccountStore()

    await account.load()

    expect(account.profile?.phone).toBe('')
  })

  it('load never throws: a failure is the «error» status and the flag stays unknown', async () => {
    mockFetch(() => problem(500, { title: 'Error' }))
    const account = useAccountStore()

    await expect(account.load()).resolves.toBeUndefined()

    expect(account.status).toBe('error')
    expect(account.temporaryPassword).toBeNull()
  })

  it('ensureLoaded reads once, and again after a failure', async () => {
    const fetchMock = mockFetch(() => problem(500, {}))
    const account = useAccountStore()

    await account.ensureLoaded()
    await account.ensureLoaded()
    expect(fetchMock).toHaveBeenCalledTimes(2)

    accountHandler()
    await account.ensureLoaded()
    const reads = vi.mocked(fetch).mock.calls.length
    await account.ensureLoaded()
    expect(vi.mocked(fetch).mock.calls).toHaveLength(reads)
    expect(account.status).toBe('ready')
  })

  it('save sends the three data with the names of the contract and keeps the answer', async () => {
    const { calls } = accountHandler()
    const account = useAccountStore()

    await account.save({ name: 'Laura B.', phone: '88880000', email: 'laura@correo.cr' })

    expect(calls[0]).toEqual({
      route: 'PUT /api/v1/cuenta',
      body: { nombre: 'Laura B.', telefono: '88880000', correo: 'laura@correo.cr' },
    })
    expect(account.profile).toEqual({
      name: 'Laura B.',
      phone: '88880000',
      email: 'laura@correo.cr',
    })
  })

  it('save throws the ApiError of a 422 on the email and keeps the profile', async () => {
    accountHandler({
      put: () =>
        problem(422, {
          type: 'http://localhost/problemas/datos-invalidos',
          errores: { correo: ['Ya existe una cuenta con ese correo.'] },
        }),
    })
    const account = useAccountStore()
    await account.load()

    const failure = await account
      .save({ name: 'Laura', phone: '88881234', email: 'otra@correo.cr' })
      .catch((error: unknown) => error)

    expect(failure).toBeInstanceOf(ApiError)
    expect((failure as ApiError).errors.correo).toEqual(['Ya existe una cuenta con ese correo.'])
    expect(account.profile?.email).toBe('laura.brenes@correo.cr')
  })

  it('changePassword sends both passwords and the password stops being temporary', async () => {
    const { calls } = accountHandler({ account: { ...ACCOUNT, contrasena_temporal: true } })
    const account = useAccountStore()
    await account.load()

    await account.changePassword('Temporal#2026', 'Nueva#Clave9')

    expect(calls[1]).toEqual({
      route: 'PUT /api/v1/cuenta/contrasena',
      body: { contrasena_actual: 'Temporal#2026', contrasena_nueva: 'Nueva#Clave9' },
    })
    expect(account.temporaryPassword).toBe(false)
  })

  it('a wrong current password stays temporary and throws the 422', async () => {
    accountHandler({
      password: () =>
        problem(422, { errores: { contrasena_actual: ['La contraseña actual no es correcta.'] } }),
    })
    const account = useAccountStore()
    account.markTemporary()

    await expect(account.changePassword('mala', 'Nueva#Clave9')).rejects.toBeInstanceOf(ApiError)
    expect(account.temporaryPassword).toBe(true)
  })

  it('markTemporary and clear', () => {
    const account = useAccountStore()

    account.markTemporary()
    expect(account.temporaryPassword).toBe(true)

    account.clear()
    expect(account.temporaryPassword).toBeNull()
    expect(account.status).toBe('idle')
  })

  it('an answer that arrives after clear() is dropped', async () => {
    let release: (response: Response) => void = () => {}
    const fetchMock = mockFetch(() => new Promise<Response>((resolve) => (release = resolve)))
    const account = useAccountStore()

    const reading = account.load()
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
    account.clear()
    release(jsonResponse(200, { data: ACCOUNT }))
    await reading

    expect(account.profile).toBeNull()
    expect(account.status).toBe('idle')
  })

  it('closing the session forgets the account', async () => {
    signIn()
    accountHandler()
    const account = useAccountStore()
    await account.load()

    useSessionStore().clear()

    expect(account.profile).toBeNull()
    expect(account.temporaryPassword).toBeNull()
  })

  it('the email changed in «Datos» is the one the session shows', () => {
    signIn('antiguo@correo.cr')
    const session = useSessionStore()

    session.setEmail('nuevo@correo.cr')

    expect(session.person?.email).toBe('nuevo@correo.cr')
    expect(sessionStorage.getItem('barberhub.session')).toContain('nuevo@correo.cr')
  })
})
