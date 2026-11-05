import { ref } from 'vue'

import type { ApiError } from '@/api'

/**
 * Turns an ApiError into per-field messages and one general message.
 * `fieldMap` translates the API attribute names (JSON in Spanish) to the form's own field names.
 */
export function useFormErrors<Field extends string>(fieldMap: Record<string, Field>) {
  const fieldErrors = ref<Partial<Record<Field, string>>>({})
  const generalError = ref('')

  function clear(): void {
    fieldErrors.value = {}
    generalError.value = ''
  }

  function clearField(field: Field): void {
    delete fieldErrors.value[field]
  }

  function setField(field: Field, message: string): void {
    fieldErrors.value[field] = message
  }

  /** A 422 fills the fields with the API's messages; any other error becomes the general message. */
  function setFromApiError(error: ApiError): void {
    clear()
    if (error.status === 422) {
      for (const [apiField, messages] of Object.entries(error.errors)) {
        const field = fieldMap[apiField]
        if (field !== undefined && messages.length > 0) {
          fieldErrors.value[field] = messages.join(' ')
        }
      }
      if (Object.keys(fieldErrors.value).length > 0) return
    }
    generalError.value = error.detail
  }

  /** First field, in form order, that has an error. */
  function firstField(order: readonly Field[]): Field | undefined {
    return order.find((field) => fieldErrors.value[field] !== undefined)
  }

  return { fieldErrors, generalError, clear, clearField, setField, setFromApiError, firstField }
}
