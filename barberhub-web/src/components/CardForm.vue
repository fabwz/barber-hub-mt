<script setup lang="ts">
import { ref, useTemplateRef } from 'vue'

import TextField from '@/components/TextField.vue'
import type { CardDetails } from '@/composables/usePayment'

interface Props {
  /** Disables the fields while a payment is being made. */
  disabled?: boolean
  /** Problem with the card that only the processor can tell (shown under the number). */
  error?: string
}

type Field = 'number' | 'expiry' | 'cvv' | 'name'

const { disabled = false, error = '' } = defineProps<Props>()

// The card lives only in these refs. `collect` hands it out once and `reset` wipes it.
const number = ref('')
const expiry = ref('')
const cvv = ref('')
const holder = ref('')
const errors = ref<Partial<Record<Field, string>>>({})

const fields = {
  number: useTemplateRef<InstanceType<typeof TextField>>('numberField'),
  expiry: useTemplateRef<InstanceType<typeof TextField>>('expiryField'),
  cvv: useTemplateRef<InstanceType<typeof TextField>>('cvvField'),
  name: useTemplateRef<InstanceType<typeof TextField>>('nameField'),
}

const digits = (value: string, max: number): string => value.replace(/\D/g, '').slice(0, max)

/** «4242 4242 4242 4242»: groups of four. */
const formatNumber = (value: string): string => digits(value, 19).replace(/(\d{4})(?=\d)/g, '$1 ')

/** «08/28»: the slash appears after the month. */
const formatExpiry = (value: string): string => {
  const raw = digits(value, 4)
  return raw.length > 2 ? `${raw.slice(0, 2)}/${raw.slice(2)}` : raw
}

const formatCvv = (value: string): string => digits(value, 4)

/** Letters (with accents), spaces, apostrophes, dots and hyphens: nobody is called «José 99». */
const formatName = (value: string): string => value.replace(/[^\p{L}\s'.-]/gu, '').slice(0, 40)

function onNumber(value: string): void {
  number.value = formatNumber(value)
  delete errors.value.number
}

function onExpiry(value: string): void {
  expiry.value = formatExpiry(value)
  delete errors.value.expiry
}

function onCvv(value: string): void {
  cvv.value = formatCvv(value)
  delete errors.value.cvv
}

function onName(value: string): void {
  holder.value = formatName(value)
  delete errors.value.name
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

/** Validates the form. With a problem it marks the fields, focuses the first and returns `null`. */
function collect(): CardDetails | null {
  const found: Partial<Record<Field, string>> = {}
  const rawNumber = digits(number.value, 19)
  if (rawNumber.length < 13) {
    found.number = 'Ingrese el número de la tarjeta, de 13 a 19 dígitos.'
  }

  const [month = 0, year = 0] = expiry.value.split('/').map(Number)
  const now = new Date()
  const fullYear = 2000 + year
  const past =
    fullYear < now.getFullYear() || (fullYear === now.getFullYear() && month < now.getMonth() + 1)
  if (expiry.value.length !== 5 || month < 1 || month > 12 || past) {
    found.expiry = 'Ingrese el vencimiento como MM/AA, con una fecha que no haya pasado.'
  }

  if (cvv.value.length < 3) found.cvv = 'Ingrese el código de 3 o 4 dígitos.'
  if (holder.value.trim() === '') found.name = 'Ingrese el nombre como aparece en la tarjeta.'

  errors.value = found
  const first = (['number', 'expiry', 'cvv', 'name'] as const).find((field) => found[field])
  if (first) {
    fields[first].value?.focus()
    return null
  }
  return {
    number: rawNumber,
    expMonth: month,
    expYear: fullYear,
    cvv: cvv.value,
    holderName: holder.value.trim(),
  }
}

/** Wipes every value. The payment calls it as soon as the card has been used. */
function reset(): void {
  number.value = ''
  expiry.value = ''
  cvv.value = ''
  holder.value = ''
  errors.value = {}
}

defineExpose({ collect, reset })
</script>

<template>
  <fieldset class="flex flex-col gap-4" :disabled="disabled">
    <legend class="sr-only">Datos de la tarjeta</legend>
    <TextField
      ref="numberField"
      label="Número de tarjeta"
      :model-value="number"
      :error="errors.number || error"
      inputmode="numeric"
      autocomplete="cc-number"
      maxlength="23"
      placeholder="1234 5678 9012 3456"
      class="tabular-nums"
      @update:model-value="onNumber"
      @input="keep($event, formatNumber)"
    />
    <div class="grid grid-cols-2 gap-4">
      <TextField
        ref="expiryField"
        label="Vencimiento"
        :model-value="expiry"
        :error="errors.expiry"
        inputmode="numeric"
        autocomplete="cc-exp"
        maxlength="5"
        placeholder="MM/AA"
        class="tabular-nums"
        @update:model-value="onExpiry"
        @input="keep($event, formatExpiry)"
      />
      <TextField
        ref="cvvField"
        label="CVV"
        :model-value="cvv"
        :error="errors.cvv"
        inputmode="numeric"
        autocomplete="cc-csc"
        maxlength="4"
        placeholder="123"
        class="tabular-nums"
        @update:model-value="onCvv"
        @input="keep($event, formatCvv)"
      />
    </div>
    <TextField
      ref="nameField"
      label="Nombre en la tarjeta"
      :model-value="holder"
      :error="errors.name"
      autocomplete="cc-name"
      maxlength="40"
      placeholder="Como aparece en la tarjeta"
      @update:model-value="onName"
      @input="keep($event, formatName)"
    />
  </fieldset>
</template>
