<script setup lang="ts">
import { computed, ref, useId, useTemplateRef } from 'vue'
import { ChevronDown, CircleAlert } from 'lucide-vue-next'

import TextField from '@/components/TextField.vue'
import type { SinpeDetails } from '@/composables/usePayment'

type Field = 'phone' | 'idType' | 'identification'

interface Props {
  disabled?: boolean
  /** Messages from the API (422) for each field. */
  errors?: Partial<Record<Field, string>>
}

const { disabled = false, errors: serverErrors = {} } = defineProps<Props>()

/**
 * Types of identification the contract accepts, with how each one is typed: digits (shown with
 * hyphens) or letters and digits. The API only says «con los guiones de su tipo»: the shapes are
 * the usual ones of Costa Rica and are to be confirmed with ONVO.
 */
interface IdRule {
  label: string
  placeholder: string
  /** Most characters (digits or letters) it takes. */
  max: number
  /** Fewest characters that make it complete. */
  min: number
  /** Letters and digits instead of digits only. */
  alphanumeric?: boolean
  /** Sizes of the hyphen-separated groups. */
  groups?: number[]
}

const ID_RULES: Record<string, IdRule> = {
  '0': { label: 'Nacional', placeholder: '1-1393-1919', max: 10, min: 9, groups: [1, 4, 4] },
  '1': { label: 'Residente', placeholder: '155812345678', max: 12, min: 11 },
  '2': {
    label: 'Entidad estatal',
    placeholder: '3-007-123456',
    max: 10,
    min: 10,
    groups: [1, 3, 6],
  },
  '3': { label: 'Jurídica', placeholder: '3-101-123456', max: 10, min: 10, groups: [1, 3, 6] },
  '4': {
    label: 'Institución autónoma',
    placeholder: '4-000-123456',
    max: 10,
    min: 10,
    groups: [1, 3, 6],
  },
  '5': { label: 'Diplomático', placeholder: 'AB1234567', max: 20, min: 5, alphanumeric: true },
  '9': { label: 'Extranjero', placeholder: 'AB1234567', max: 20, min: 5, alphanumeric: true },
}

const PHONE_DIGITS = 8

const phone = ref('')
const idType = ref('')
const identification = ref('')
const local = ref<Partial<Record<Field, string>>>({})

const phoneField = useTemplateRef<InstanceType<typeof TextField>>('phoneField')
const typeSelect = useTemplateRef<HTMLSelectElement>('typeSelect')
const identificationField = useTemplateRef<InstanceType<typeof TextField>>('identificationField')

const typeId = useId()
const typeErrorId = `${typeId}-error`
const typeError = computed(() => local.value.idType || serverErrors.idType || '')
const rule = computed<IdRule | undefined>(() => ID_RULES[idType.value])

function clear(field: Field): void {
  delete local.value[field]
}

/** 8 digits shown as 1234-5678. A pasted +506 prefix is dropped. */
function formatPhone(value: string): string {
  let digits = value.replace(/\D/g, '')
  if (digits.length > PHONE_DIGITS && digits.startsWith('506')) digits = digits.slice(3)
  digits = digits.slice(0, PHONE_DIGITS)
  return digits.length > 4 ? `${digits.slice(0, 4)}-${digits.slice(4)}` : digits
}

/** Keeps only what the chosen type takes and puts the hyphens in. */
function formatIdentification(type: string, value: string): string {
  const current = ID_RULES[type]
  if (current === undefined) return ''
  if (current.alphanumeric) {
    return value
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, '')
      .slice(0, current.max)
  }
  const digits = value.replace(/\D/g, '').slice(0, current.max)
  // A national identification has 9 digits (1-1393-1919) or 10 with a leading zero (01-1393-1919).
  const groups = type === '0' && digits.length > 9 ? [2, 4, 4] : (current.groups ?? [current.max])
  const parts: string[] = []
  let start = 0
  for (const size of groups) {
    if (start >= digits.length) break
    parts.push(digits.slice(start, start + size))
    start += size
  }
  return parts.join('-')
}

/**
 * A key the field does not take leaves the cleaned value equal to the one the model already has, so
 * Vue does not redraw and the input would keep showing it: the field is fixed here, key by key.
 */
function keep(event: Event, format: (value: string) => string): void {
  const input = event.target as HTMLInputElement
  const clean = format(input.value)
  if (input.value !== clean) input.value = clean
}

function onPhone(value: string): void {
  phone.value = formatPhone(value)
  clear('phone')
}

function onType(): void {
  identification.value = formatIdentification(idType.value, identification.value)
  clear('idType')
  clear('identification')
}

function onIdentification(value: string): void {
  identification.value = formatIdentification(idType.value, value)
  clear('identification')
}

function collect(): SinpeDetails | null {
  const found: Partial<Record<Field, string>> = {}
  const phoneDigits = phone.value.replace(/\D/g, '')
  if (phoneDigits.length !== PHONE_DIGITS) {
    found.phone = 'Ingrese los 8 dígitos de su teléfono, por ejemplo 1234-5678.'
  }
  if (idType.value === '') {
    found.idType = 'Elija el tipo de identificación.'
  } else {
    const size = identification.value.replace(/[^A-Za-z0-9]/g, '').length
    if (size < (rule.value?.min ?? 1)) {
      found.identification = `Ingrese su identificación completa, por ejemplo ${rule.value?.placeholder}.`
    }
  }
  local.value = found
  if (found.phone) phoneField.value?.focus()
  else if (found.idType) typeSelect.value?.focus()
  else if (found.identification) identificationField.value?.focus()
  if (Object.keys(found).length > 0) return null
  return {
    phone: `+506${phoneDigits}`,
    idType: idType.value,
    identification: identification.value,
  }
}

defineExpose({ collect })
</script>

<template>
  <fieldset class="flex flex-col gap-4" :disabled="disabled">
    <legend class="sr-only">Datos para el SINPE Móvil</legend>
    <TextField
      ref="phoneField"
      label="Teléfono"
      type="tel"
      inputmode="numeric"
      autocomplete="tel"
      placeholder="1234-5678"
      maxlength="9"
      :model-value="phone"
      :error="local.phone || serverErrors.phone"
      @update:model-value="onPhone"
      @input="keep($event, formatPhone)"
    />

    <div class="flex flex-col gap-2">
      <label :for="typeId" class="text-sm font-medium text-fg">Tipo de identificación</label>
      <div class="relative">
        <select
          :id="typeId"
          ref="typeSelect"
          v-model="idType"
          :aria-invalid="typeError ? 'true' : undefined"
          :aria-describedby="typeError ? typeErrorId : undefined"
          class="min-h-12 w-full cursor-pointer appearance-none rounded-field border bg-canvas pr-11 pl-4 text-base font-normal text-fg transition-colors duration-150 ease-out hover:border-fg-muted focus:border-accent focus:outline-none disabled:cursor-not-allowed"
          :class="typeError ? 'border-danger-border' : 'border-field'"
          @change="onType"
        >
          <option value="" disabled>Seleccione</option>
          <option v-for="(item, value) in ID_RULES" :key="value" :value="value">
            {{ item.label }}
          </option>
        </select>
        <ChevronDown
          :size="18"
          :stroke-width="1.5"
          class="pointer-events-none absolute top-3.5 right-4 text-fg-muted"
          aria-hidden="true"
        />
      </div>
      <p v-if="typeError" :id="typeErrorId" class="flex items-start gap-2 text-sm text-danger">
        <CircleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
        <span>{{ typeError }}</span>
      </p>
    </div>

    <TextField
      ref="identificationField"
      label="Identificación"
      autocomplete="off"
      :disabled="idType === ''"
      :inputmode="rule?.alphanumeric ? 'text' : 'numeric'"
      :placeholder="rule?.placeholder ?? 'Elija primero el tipo'"
      :maxlength="rule ? rule.max + 2 : undefined"
      :model-value="identification"
      :error="local.identification || serverErrors.identification"
      @update:model-value="onIdentification"
      @input="keep($event, (value) => formatIdentification(idType, value))"
    />
  </fieldset>
</template>
