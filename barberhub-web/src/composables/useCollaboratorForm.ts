import { computed, reactive, ref } from 'vue'

import { isApiError, type ApiError } from '@/api'
import { isValidTime } from '@/composables/timeOfDay'
import { generateTemporaryPassword } from '@/composables/temporaryPassword'
import { WEEKDAYS, weekdayLabel } from '@/composables/weekdays'
import { useTeamStore, type Collaborator, type ScheduleEntry } from '@/stores/team'

/** A shift or a break the person is typing: the hours may still be empty or incomplete. */
export interface HourRow {
  key: string
  start: string
  end: string
}

export interface DayDraft {
  day: number
  label: string
  /** «Trabaja este día». A working day has at least one shift. */
  working: boolean
  shifts: HourRow[]
  breaks: HourRow[]
}

export type FormMode = 'create' | 'own' | 'edit'

const NAME_REQUIRED = 'Escriba el nombre completo.'
const EMAIL_REQUIRED = 'Escriba el correo electrónico.'
const SERVICES_REQUIRED = 'Elija al menos un servicio.'
const INVALID_TIME = 'Escriba las horas en formato HH:MM, de 00:00 a 23:59.'
const END_NOT_AFTER = 'La hora de fin debe ser posterior a la de inicio.'
const SHIFT_REQUIRED = 'Agregue al menos un turno o desmarque este día.'
const OWN_PROFILE_EXISTS = 'Usted ya tiene un perfil de colaborador.'
const FAILED = 'No pudimos guardar los datos. Inténtelo de nuevo.'

/** What the owner needs to hand over, and to read once, when an account was created. */
export interface CreatedAccount {
  name: string
  email: string
  password: string
  services: number
  schedule: string
}

/** «Lunes a viernes, 09:00 a 17:00»: the days that share the same shifts are grouped, in order. */
export function summarizeSchedule(days: DayDraft[]): string {
  const patterns = new Map<string, number[]>()
  for (const item of days) {
    if (!item.working) continue
    const text = item.shifts.map((shift) => `${shift.start} a ${shift.end}`).join(' y ')
    patterns.set(text, [...(patterns.get(text) ?? []), item.day])
  }
  if (patterns.size === 0) return 'Sin horario'
  return [...patterns]
    .map(([hours, list]) => {
      const consecutive = list.every((day, index) => index === 0 || day === list[index - 1]! + 1)
      const first = weekdayLabel(list[0]!)
      const names =
        list.length === 1
          ? first
          : consecutive
            ? `${first} a ${weekdayLabel(list[list.length - 1]!).toLowerCase()}`
            : list
                .map((day, index) => (index === 0 ? first : weekdayLabel(day).toLowerCase()))
                .join(', ')
      return `${names}, ${hours}`
    })
    .join('; ')
}

let rowCounter = 0
function newRow(start = '', end = ''): HourRow {
  return { key: `row-${rowCounter++}`, start, end }
}

/**
 * The form of a collaborator (RF-05): account data, services and the schedule with breaks. Saving is up to three calls
 * (the account, the services, the schedule) that cannot be one transaction. The id of what was created is kept, and a
 * new try repeats only what the API does not have yet: it never creates the account twice (that would be a 422 for a
 * repeated email), and a step that already went through with the same data is not sent again. The temporary password is
 * made once, on the first try, and lives only here.
 */
export function useCollaboratorForm(barberia: string, mode: FormMode) {
  const team = useTeamStore()

  const name = ref('')
  const email = ref('')
  const selected = ref<string[]>([])
  const days = reactive<DayDraft[]>(
    WEEKDAYS.map((item) => ({
      day: item.day,
      label: item.label,
      working: false,
      shifts: [],
      breaks: [],
    })),
  )

  const fieldErrors = reactive<{ name: string; email: string; services: string }>({
    name: '',
    email: '',
    services: '',
  })
  /** Messages of a row (a shift or a break) or of a whole day, by key. */
  const rowErrors = reactive<Record<string, string>>({})
  const generalError = ref('')
  const saving = ref(false)

  const collaboratorId = ref<string | null>(null)
  const password = ref<string | null>(null)
  const created = ref<CreatedAccount | null>(null)
  const finished = ref(false)
  let sentServices: string | null = null
  let sentSchedule: string | null = null

  function clearErrors(): void {
    fieldErrors.name = ''
    fieldErrors.email = ''
    fieldErrors.services = ''
    for (const key of Object.keys(rowErrors)) delete rowErrors[key]
    generalError.value = ''
  }

  /** Fills the form with a collaborator that already exists (edit). */
  function load(collaborator: Collaborator): void {
    collaboratorId.value = collaborator.id
    name.value = collaborator.nombre
    email.value = collaborator.correo
    selected.value = [...collaborator.servicios]
    for (const item of days) {
      const shifts = collaborator.turnos.filter((shift) => shift.dia === item.day)
      item.working = shifts.length > 0
      item.shifts = shifts.map((shift) => newRow(shift.inicia, shift.termina))
      item.breaks = collaborator.pausas
        .filter((pause) => pause.dia === item.day)
        .map((pause) => newRow(pause.inicia, pause.termina))
    }
  }

  function setWorking(day: DayDraft, working: boolean): void {
    day.working = working
    delete rowErrors[`day-${day.day}`]
    if (working && day.shifts.length === 0) day.shifts.push(newRow())
    // A day that is not worked keeps no shifts and no breaks: there is nothing to send for it.
    if (!working) {
      day.shifts = []
      day.breaks = []
    }
  }

  function addShift(day: DayDraft): void {
    delete rowErrors[`day-${day.day}`]
    day.shifts.push(newRow())
  }
  function addBreak(day: DayDraft): void {
    day.breaks.push(newRow())
  }
  function removeRow(day: DayDraft, kind: 'shifts' | 'breaks', key: string): void {
    day[kind] = day[kind].filter((row) => row.key !== key)
    delete rowErrors[key]
    if (kind === 'shifts' && day.shifts.length === 0) day.breaks = []
  }
  function setHour(row: HourRow, part: 'start' | 'end', value: string): void {
    row[part] = value
    delete rowErrors[row.key]
  }

  /** The body of the schedule and, for each position, the row it came from: the API names errors by position. */
  function scheduleBody() {
    const turnos: ScheduleEntry[] = []
    const pausas: ScheduleEntry[] = []
    const shiftKeys: string[] = []
    const breakKeys: string[] = []
    for (const day of days) {
      if (!day.working) continue
      for (const shift of day.shifts) {
        turnos.push({ dia: day.day, inicia: shift.start, termina: shift.end })
        shiftKeys.push(shift.key)
      }
      for (const pause of day.breaks) {
        pausas.push({ dia: day.day, inicia: pause.start, termina: pause.end })
        breakKeys.push(pause.key)
      }
    }
    return { turnos, pausas, shiftKeys, breakKeys }
  }

  /** The quick check before any trip; the API still has the last word on every rule. */
  function validate(): boolean {
    clearErrors()
    if (mode === 'create') {
      if (name.value.trim() === '') fieldErrors.name = NAME_REQUIRED
      if (email.value.trim() === '') fieldErrors.email = EMAIL_REQUIRED
    }
    if (selected.value.length === 0) fieldErrors.services = SERVICES_REQUIRED
    for (const day of days) {
      if (!day.working) continue
      if (day.shifts.length === 0) rowErrors[`day-${day.day}`] = SHIFT_REQUIRED
      for (const row of [...day.shifts, ...day.breaks]) {
        if (!isValidTime(row.start) || !isValidTime(row.end)) rowErrors[row.key] = INVALID_TIME
        else if (row.end <= row.start) rowErrors[row.key] = END_NOT_AFTER
      }
    }
    return (
      fieldErrors.name === '' &&
      fieldErrors.email === '' &&
      fieldErrors.services === '' &&
      Object.keys(rowErrors).length === 0
    )
  }

  /** Puts each error of a 422 in its place: the field, the service list or the row of the shift or the break. */
  function showApiError(error: unknown, rows?: { shiftKeys: string[]; breakKeys: string[] }): void {
    if (!isApiError(error)) {
      generalError.value = FAILED
      return
    }
    if (error.status === 401) return
    let placed = false
    if (error.status === 422) {
      for (const [field, messages] of Object.entries((error as ApiError).errors)) {
        const text = messages.join(' ')
        if (field === 'nombre') fieldErrors.name = text
        else if (field === 'correo') fieldErrors.email = text
        else if (/^servicios(\.\d+)?$/.test(field)) fieldErrors.services = text
        else {
          const match = /^(turnos|pausas)\.(\d+)/.exec(field)
          const key =
            match === null
              ? undefined
              : (match[1] === 'turnos' ? rows?.shiftKeys : rows?.breakKeys)?.[Number(match[2])]
          if (key === undefined) continue
          rowErrors[key] = rowErrors[key] ? `${rowErrors[key]} ${text}` : text
        }
        placed = true
      }
    }
    if (!placed) generalError.value = error.detail
  }

  async function submit(): Promise<void> {
    // A created account is final: what is left is its screen. Anything else may be saved again.
    if (saving.value || created.value !== null) return
    finished.value = false
    if (!validate()) return
    saving.value = true
    try {
      // 1. The account or the profile. Once it exists it is never created again.
      if (collaboratorId.value === null) {
        try {
          if (mode === 'own') {
            collaboratorId.value = (await team.createOwnProfile(barberia)).id
          } else {
            password.value = password.value ?? generateTemporaryPassword()
            collaboratorId.value = (
              await team.createAccount(barberia, {
                nombre: name.value.trim(),
                correo: email.value.trim(),
                contrasena_temporal: password.value,
              })
            ).id
          }
        } catch (error) {
          if (mode === 'own' && isApiError(error) && error.status === 409) {
            generalError.value = OWN_PROFILE_EXISTS
          } else {
            showApiError(error)
          }
          return
        }
      }
      const id = collaboratorId.value

      // 2. The services, unless the API already has exactly this list.
      const servicesKey = JSON.stringify([...selected.value].sort())
      if (servicesKey !== sentServices) {
        try {
          await team.replaceServices(barberia, id, selected.value)
          sentServices = servicesKey
        } catch (error) {
          showApiError(error)
          return
        }
      }

      // 3. The schedule. A new collaborator with no shifts has nothing to send; one being edited may clear it.
      const body = scheduleBody()
      const scheduleKey = JSON.stringify({ t: body.turnos, p: body.pausas })
      const nothingToSend = mode !== 'edit' && body.turnos.length === 0 && body.pausas.length === 0
      if (scheduleKey !== sentSchedule && !nothingToSend) {
        try {
          await team.replaceSchedule(barberia, id, { turnos: body.turnos, pausas: body.pausas })
          sentSchedule = scheduleKey
        } catch (error) {
          showApiError(error, body)
          return
        }
      }

      if (mode === 'create' && password.value !== null) {
        created.value = {
          name: name.value.trim(),
          email: email.value.trim(),
          password: password.value,
          services: selected.value.length,
          schedule: summarizeSchedule(days),
        }
      }
      finished.value = true
    } finally {
      saving.value = false
    }
  }

  /** The account exists but something after it is still missing: the owner must still get the password. */
  const partial = computed(
    () => collaboratorId.value !== null && !finished.value && mode === 'create',
  )

  return {
    name,
    email,
    selected,
    days,
    fieldErrors,
    rowErrors,
    generalError,
    saving,
    collaboratorId,
    password,
    created,
    finished,
    partial,
    load,
    setWorking,
    addShift,
    addBreak,
    removeRow,
    setHour,
    submit,
  }
}
