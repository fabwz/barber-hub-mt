<script setup lang="ts">
import { nextTick, onBeforeUnmount, useId, useTemplateRef, watch } from 'vue'

import BaseButton from '@/components/BaseButton.vue'

interface Props {
  /** The question, e.g. «¿Inactivar «Rapado»?». */
  title: string
  /** What happens if the person confirms. */
  description: string
  /** The safe answer, e.g. «No, mantenerlo activo». It is the main button and takes the focus. */
  cancelLabel: string
  /** The action, e.g. «Sí, inactivar». Without it the dialog only informs and has the one button that closes it. */
  confirmLabel?: string
  /** Holds both buttons and Escape while the action runs. */
  loading?: boolean
}

const { loading = false } = defineProps<Props>()

const open = defineModel<boolean>({ required: true })

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const titleId = useId()
const descriptionId = useId()
const dialog = useTemplateRef<HTMLElement>('dialog')
const cancelButton = useTemplateRef<InstanceType<typeof BaseButton>>('cancelButton')

// Whatever had the focus when the dialog opened gets it back when it closes.
let opener: HTMLElement | null = null

const FOCUSABLE =
  'button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'

watch(
  open,
  async (isOpen) => {
    if (isOpen) {
      opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
      await nextTick()
      focusCancel()
    } else {
      const target = opener
      opener = null
      await nextTick()
      target?.focus()
    }
  },
  { flush: 'post' },
)

onBeforeUnmount(() => {
  if (open.value) opener?.focus()
})

function focusCancel(): void {
  const element = cancelButton.value?.$el
  if (element instanceof HTMLElement) element.focus()
}

function cancel(): void {
  if (loading) return
  open.value = false
  emit('cancel')
}

/** Escape cancels and Tab keeps the focus inside the dialog. */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    cancel()
    return
  }
  if (event.key !== 'Tab' || dialog.value === null) return
  const focusable = Array.from(dialog.value.querySelectorAll<HTMLElement>(FOCUSABLE))
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (first === undefined || last === undefined) {
    event.preventDefault()
    return
  }
  const current = document.activeElement
  if (event.shiftKey && (current === first || !dialog.value.contains(current))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (current === last || !dialog.value.contains(current))) {
    event.preventDefault()
    first.focus()
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 grid place-items-center bg-canvas/80 p-5"
      data-testid="dialog-backdrop"
      @keydown="onKeydown"
    >
      <div
        ref="dialog"
        role="alertdialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        :aria-describedby="descriptionId"
        class="w-full max-w-md rounded-card border border-line bg-surface p-6"
      >
        <h2 :id="titleId" class="text-lg font-medium text-fg">{{ title }}</h2>
        <p :id="descriptionId" class="mt-2 text-sm text-fg-muted">{{ description }}</p>
        <!-- Whatever the question needs to show, e.g. the list of what blocks the action. -->
        <slot />
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton
            v-if="confirmLabel"
            variant="secondary"
            :loading="loading"
            @click="emit('confirm')"
            >{{ confirmLabel }}</BaseButton
          >
          <BaseButton ref="cancelButton" :disabled="loading" @click="cancel">{{
            cancelLabel
          }}</BaseButton>
        </div>
      </div>
    </div>
  </Teleport>
</template>
