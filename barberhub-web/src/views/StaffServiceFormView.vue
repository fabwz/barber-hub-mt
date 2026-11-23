<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { isApiError, type ApiError } from '@/api'
import BackLink from '@/components/BackLink.vue'
import BaseButton from '@/components/BaseButton.vue'
import ErrorState from '@/components/ErrorState.vue'
import ImageField from '@/components/ImageField.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import SwitchField from '@/components/SwitchField.vue'
import TextField from '@/components/TextField.vue'
import { restrict } from '@/composables/fieldInput'
import { useFormErrors } from '@/composables/useFormErrors'
import { useServiceAdminStore, type AdminService } from '@/stores/services'

// «Nuevo servicio» and «Editar servicio» (RF-03, RF-46; lámina 31). One form for both. The image is a second
// call: the API needs the id of the service, so a new one is created first and its image goes right after (#46).
// If the upload fails the service already exists: its id is kept and a new try sends only the image.
type Field = 'name' | 'duration' | 'price' | 'image'
const FIELD_ORDER: readonly Field[] = ['name', 'duration', 'price', 'image']

const DURATION_PROBLEM = 'La duración debe estar entre 10 y 180 minutos, en múltiplos de 5.'
const PRICE_PROBLEM = 'El precio debe ser mayor que ₡0, en colones enteros.'
const NAME_PROBLEM = 'Escriba el nombre del servicio.'
const IMAGE_FAILED = 'No pudimos subir la imagen. Inténtelo de nuevo.'
const MAX_NAME = 120

const route = useRoute()
const store = useServiceAdminStore()

const shopId = computed(() => String(route.params.barberia))
const servicesLink = computed(() => ({
  name: 'staff-services',
  params: { barberia: shopId.value },
}))
/** The service being edited, or the one just created. Null while a new one has not been saved. */
const serviceId = ref<string | null>(
  typeof route.params.servicio === 'string' ? route.params.servicio : null,
)
const editing = computed(() => serviceId.value !== null)

const name = ref('')
const duration = ref('')
const price = ref('')
const active = ref(true)
const image = ref<File | null>(null)
const currentImage = ref<string | null>(null)

/** What the API holds now, to send only what changed. */
const saved = ref<AdminService | null>(null)

const loading = ref(false)
const notFound = ref(false)
const saving = ref(false)
const done = ref(false)

const errors = useFormErrors<Field>({
  nombre: 'name',
  duracion_minutos: 'duration',
  precio: 'price',
  imagen: 'image',
})

const fields = {
  name: useTemplateRef<InstanceType<typeof TextField>>('nameField'),
  duration: useTemplateRef<InstanceType<typeof TextField>>('durationField'),
  price: useTemplateRef<InstanceType<typeof TextField>>('priceField'),
  image: useTemplateRef<InstanceType<typeof ImageField>>('imageField'),
}

function fill(service: AdminService): void {
  saved.value = service
  name.value = service.nombre
  duration.value = String(service.duracion_minutos)
  price.value = String(service.precio)
  active.value = service.activo
  currentImage.value = service.imagen
}

async function loadService(): Promise<void> {
  if (serviceId.value === null) return
  notFound.value = false
  loading.value = true
  // A direct visit or a reload has no list yet; the API has no call for a single service.
  if (store.shopId !== shopId.value || store.items.length === 0) await store.load(shopId.value)
  loading.value = false
  const found = store.items.find((item) => item.id === serviceId.value)
  if (found === undefined) notFound.value = true
  else fill(found)
}

onMounted(loadService)

function digitsOnly(value: string): string {
  return value.replace(/\D/g, '')
}

function onNumber(event: Event, field: 'duration' | 'price'): void {
  restrict(event, digitsOnly, field === 'duration' ? duration : price)
  errors.clearField(field)
}

/** The quick check before the trip; the API still has the last word. */
function check(): boolean {
  errors.clear()
  if (name.value.trim() === '') errors.setField('name', NAME_PROBLEM)
  const minutes = Number(duration.value)
  if (duration.value === '' || minutes < 10 || minutes > 180 || minutes % 5 !== 0) {
    errors.setField('duration', DURATION_PROBLEM)
  }
  if (price.value === '' || Number(price.value) < 1) errors.setField('price', PRICE_PROBLEM)
  return Object.keys(errors.fieldErrors.value).length === 0
}

async function focusFirstError(): Promise<void> {
  await nextTick()
  const first = errors.firstField(FIELD_ORDER)
  if (first === undefined) return
  const target = fields[first].value
  if (target !== null && 'focus' in target) target.focus()
  else if (target !== null) target.pick()
}

/** Only the fields that differ from what the API holds. */
function changes() {
  const current = saved.value
  const patch: { nombre?: string; duracion_minutos?: number; precio?: number; activo?: boolean } =
    {}
  if (current === null || name.value.trim() !== current.nombre) patch.nombre = name.value.trim()
  if (current === null || Number(duration.value) !== current.duracion_minutos) {
    patch.duracion_minutos = Number(duration.value)
  }
  if (current === null || Number(price.value) !== current.precio) patch.precio = Number(price.value)
  if (current !== null && active.value !== current.activo) patch.activo = active.value
  return patch
}

function imageError(error: unknown): void {
  if (isApiError(error) && error.status === 422 && (error.errors.imagen?.length ?? 0) > 0) {
    errors.setField('image', error.errors.imagen!.join(' '))
  } else {
    errors.setField('image', IMAGE_FAILED)
  }
}

async function submit(): Promise<void> {
  if (saving.value) return
  done.value = false
  if (!check()) {
    await focusFirstError()
    return
  }
  saving.value = true
  try {
    // 1. The service itself. Once it exists, a new try never creates it again.
    if (serviceId.value === null) {
      const created = await store.create(shopId.value, {
        nombre: name.value.trim(),
        duracion_minutos: Number(duration.value),
        precio: Number(price.value),
      })
      serviceId.value = created.id
      fill(created)
    } else {
      const patch = changes()
      if (Object.keys(patch).length > 0)
        fill(await store.update(shopId.value, serviceId.value, patch))
    }
  } catch (error) {
    if (isApiError(error)) errors.setFromApiError(error as ApiError)
    saving.value = false
    await focusFirstError()
    return
  }

  // 2. The image, if one was chosen.
  if (image.value !== null && serviceId.value !== null) {
    try {
      fill(await store.uploadImage(shopId.value, serviceId.value, image.value))
      image.value = null
    } catch (error) {
      if (isApiError(error) && error.status === 401) return
      imageError(error)
      saving.value = false
      await focusFirstError()
      return
    }
  }

  saving.value = false
  done.value = true
}
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-2xl flex-1 px-5 py-6 md:px-8">
      <BackLink :to="servicesLink">Volver a servicios</BackLink>
      <h1 id="service-title" class="mt-4 text-2xl">
        {{ editing ? 'Editar servicio' : 'Nuevo servicio' }}
      </h1>

      <SkeletonBlock
        v-if="loading"
        shape="row"
        :count="4"
        label="Cargando el servicio"
        class="mt-6"
      />
      <ErrorState
        v-else-if="notFound"
        class="mt-6"
        message="No encontramos el servicio"
        description="Puede que ya no exista. Vuelva a la lista e inténtelo de nuevo."
        retry-label="Volver a cargar"
        @retry="loadService"
      />

      <form
        v-else
        class="mt-6 flex flex-col gap-6"
        novalidate
        aria-labelledby="service-title"
        @submit.prevent="submit"
      >
        <NoticeBanner v-if="done">Servicio guardado. Ya aparece en su lista.</NoticeBanner>
        <NoticeBanner v-if="errors.generalError.value" variant="error">{{
          errors.generalError.value
        }}</NoticeBanner>

        <TextField
          ref="nameField"
          v-model="name"
          label="Nombre del servicio"
          autocomplete="off"
          :maxlength="MAX_NAME"
          :error="errors.fieldErrors.value.name"
          @input="errors.clearField('name')"
        />

        <div class="flex flex-col gap-2">
          <TextField
            ref="durationField"
            v-model="duration"
            label="Duración (minutos)"
            inputmode="numeric"
            autocomplete="off"
            aria-describedby="duration-help"
            :error="errors.fieldErrors.value.duration"
            @input="onNumber($event, 'duration')"
          />
          <p id="duration-help" class="text-sm text-fg-muted">
            De 10 a 180 minutos, en múltiplos de 5.
          </p>
        </div>

        <div class="flex flex-col gap-2">
          <TextField
            ref="priceField"
            v-model="price"
            label="Precio (₡, IVA incluido)"
            inputmode="numeric"
            autocomplete="off"
            aria-describedby="price-help"
            :error="errors.fieldErrors.value.price"
            @input="onNumber($event, 'price')"
          />
          <p id="price-help" class="text-sm text-fg-muted">
            En colones enteros, con el IVA de 13 % ya incluido.
          </p>
        </div>

        <!-- A new service is always created active (the API has no field for it): inactivating is done from the list. -->
        <SwitchField
          v-if="editing"
          v-model="active"
          label="Servicio activo"
          description="Un servicio inactivo no se puede reservar, pero sigue visible en el historial."
        />

        <!-- No «Quitar imagen» yet: the API has no call to remove an image, only to replace it. -->
        <ImageField
          ref="imageField"
          v-model="image"
          label="Imagen del servicio (opcional)"
          hint="JPG, PNG o WebP de hasta 2 MB. Se muestra al cliente cuando elige el servicio."
          :current-url="currentImage"
          :error="errors.fieldErrors.value.image"
          :disabled="saving"
          @update:model-value="errors.clearField('image')"
        />

        <div class="flex flex-wrap items-center gap-3">
          <BaseButton type="submit" size="large" :loading="saving">Guardar servicio</BaseButton>
          <RouterLink
            :to="servicesLink"
            class="inline-flex min-h-12 items-center rounded-pill border border-line-strong px-5 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
          >
            {{ done ? 'Volver a la lista' : 'Cancelar' }}
          </RouterLink>
        </div>
      </form>
    </main>
  </div>
</template>
