<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, useTemplateRef, watch } from 'vue'
import { CircleAlert, ImageIcon, ImageUp } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import {
  IMAGE_ACCEPT,
  fileSizeLabel,
  imageProblem,
  imageTypeLabel,
  readImageSize,
  sizeLabel,
  smallImageProblem,
  type ImageSize,
} from '@/composables/imageUpload'

interface Props {
  label: string
  /** Help text under the picker, e.g. what formats and how much it weighs. */
  hint?: string
  /** Address of the image the record already has, shown until another one is chosen. */
  currentUrl?: string | null
  /** The API's answer for this field (422). It adds to the check the field does itself. */
  error?: string
  disabled?: boolean
  /** Smallest size in pixels (the banner). The file is measured before it travels. */
  minSize?: ImageSize
  /** Who the size message talks about: «El banner mide 900 × 300 px…». */
  subject?: string
  /** Shape of the preview: a square (a service), a circle (a logo) or a wide rectangle (a banner). */
  shape?: 'square' | 'circle' | 'banner'
}

const {
  label,
  hint = '',
  currentUrl = null,
  error = '',
  disabled = false,
  minSize,
  subject = 'La imagen',
  shape = 'square',
} = defineProps<Props>()

/** The chosen file, only when it passed the quick check. */
const file = defineModel<File | null>({ default: null })

const FRAME = {
  square: 'size-20 rounded-field',
  circle: 'size-20 rounded-full',
  banner: 'h-20 w-60 max-w-full rounded-card',
} as const

const input = useTemplateRef<HTMLInputElement>('input')
const id = useId()
const describedBy = computed(
  () =>
    [hint !== '' ? `${id}-hint` : '', problem.value !== '' ? `${id}-error` : '']
      .filter(Boolean)
      .join(' ') || undefined,
)

// The file that was turned down stays on screen with its error, but it is never sent.
const rejected = ref<File | null>(null)
const localError = ref('')
const problem = computed(() => localError.value || error)

const shown = computed(() => rejected.value ?? file.value)
const previewUrl = ref<string | null>(null)

function revoke(): void {
  if (previewUrl.value !== null && typeof URL.revokeObjectURL === 'function') {
    URL.revokeObjectURL(previewUrl.value)
  }
  previewUrl.value = null
}

watch(
  file,
  (chosen) => {
    revoke()
    if (chosen !== null && typeof URL.createObjectURL === 'function') {
      previewUrl.value = URL.createObjectURL(chosen)
    }
  },
  { immediate: true },
)
onBeforeUnmount(revoke)

const imageSource = computed(() => previewUrl.value ?? (shown.value === null ? currentUrl : null))

function pick(): void {
  input.value?.click()
}

/** The size of the file shown or just chosen, when it was measured. */
const measured = ref<ImageSize | null>(null)

async function onChange(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement
  const chosen = target.files?.[0]
  // The same file chosen twice must fire again, so the input is emptied.
  target.value = ''
  if (chosen === undefined) return
  measured.value = null
  let message = imageProblem(chosen)
  if (message === '' && minSize !== undefined) {
    const size = await readImageSize(chosen)
    measured.value = size
    if (size !== null) message = smallImageProblem(size, minSize, subject)
  }
  localError.value = message
  if (message !== '') {
    rejected.value = chosen
    file.value = null
    return
  }
  rejected.value = null
  file.value = chosen
}

defineExpose({ pick })
</script>

<template>
  <fieldset class="flex flex-col gap-2" :disabled="disabled">
    <legend class="mb-3 text-sm font-medium text-fg">{{ label }}</legend>
    <div
      :class="shape === 'banner' ? 'flex flex-col items-start gap-3' : 'flex items-center gap-4'"
    >
      <div
        class="grid flex-none place-items-center overflow-hidden border border-line bg-surface text-fg-muted"
        :class="FRAME[shape]"
      >
        <img
          v-if="imageSource"
          :src="imageSource"
          alt="Vista previa de la imagen"
          class="size-full object-cover"
        />
        <ImageIcon v-else :size="24" :stroke-width="1.5" aria-hidden="true" />
      </div>
      <div class="flex min-w-0 flex-col items-start gap-2">
        <p v-if="shown" class="max-w-full text-sm" data-testid="image-name">
          <span class="block truncate font-medium text-fg">{{ shown.name }}</span>
          <span class="font-mono text-fg-muted"
            >{{ imageTypeLabel(shown.type) }} · {{ fileSizeLabel(shown.size)
            }}<template v-if="measured"> · {{ sizeLabel(measured) }}</template></span
          >
        </p>
        <p v-else class="text-sm text-fg-muted">
          {{ currentUrl ? 'Imagen actual' : 'Aún sin imagen' }}
        </p>
        <BaseButton
          variant="secondary"
          :disabled="disabled"
          :aria-describedby="describedBy"
          @click="pick"
        >
          <template #icon><ImageUp :size="16" :stroke-width="1.5" aria-hidden="true" /></template>
          {{ shown || currentUrl ? 'Cambiar imagen' : 'Elegir imagen' }}
        </BaseButton>
      </div>
    </div>
    <input
      ref="input"
      type="file"
      :accept="IMAGE_ACCEPT"
      class="sr-only"
      tabindex="-1"
      aria-hidden="true"
      data-testid="image-input"
      @change="onChange"
    />
    <p v-if="hint" :id="`${id}-hint`" class="text-sm text-fg-muted">{{ hint }}</p>
    <p v-if="problem" :id="`${id}-error`" class="flex items-start gap-2 text-sm text-danger">
      <CircleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
      <span>{{ problem }}</span>
    </p>
  </fieldset>
</template>
