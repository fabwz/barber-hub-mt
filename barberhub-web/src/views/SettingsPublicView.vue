<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, useTemplateRef } from 'vue'
import { useRoute } from 'vue-router'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ErrorState from '@/components/ErrorState.vue'
import ImageField from '@/components/ImageField.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import ShopHeaderPreview from '@/components/ShopHeaderPreview.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import SwitchField from '@/components/SwitchField.vue'
import TextField from '@/components/TextField.vue'
import { emailProblem } from '@/composables/fieldInput'
import { partialMessage, runSteps, type SaveStep } from '@/composables/saveSteps'
import {
  MAX_SOCIAL_LENGTH,
  NETWORK_ORDER,
  SOCIAL_NETWORKS,
  socialProblem,
  type SocialNetwork,
} from '@/composables/socialLinks'
import { useFormErrors } from '@/composables/useFormErrors'
import { useObjectUrl } from '@/composables/useObjectUrl'
import { useActiveShopStore } from '@/stores/activeShop'
import { useSettingsStore, type BusinessPatch } from '@/stores/settings'

// «Configuración · Datos públicos» (RF-01, RF-02, RF-46; lámina 34). The data, the networks, the logo, the banner
// and the directory switch go with one button but several calls, in order: the data, the directory, the logo and
// the banner. If one fails the form keeps what is left, and the message says what was saved and what was not.
type Field = 'name' | 'city' | 'email' | 'phone' | 'address' | SocialNetwork | 'logo' | 'banner'
const FIELD_ORDER: readonly Field[] = [
  'name',
  'city',
  'email',
  'phone',
  'address',
  ...NETWORK_ORDER,
  'logo',
  'banner',
]
const MIN_BANNER = { width: 1200, height: 400 }
const EMAIL_PROBLEM = 'Escriba un correo válido, como contacto@su-barberia.cr.'

const route = useRoute()
const store = useSettingsStore()
const activeShop = useActiveShopStore()
const shopId = computed(() => String(route.params.barberia))

const name = ref('')
const city = ref('')
const email = ref('')
const phone = ref('')
const address = ref('')
const links = reactive<Record<SocialNetwork, string>>({
  whatsapp: '',
  instagram: '',
  facebook: '',
  tiktok: '',
})
const logo = ref<File | null>(null)
const banner = ref<File | null>(null)
const listed = ref(false)

const saving = ref(false)
const done = ref(false)
const errors = useFormErrors<Field>({
  nombre: 'name',
  ciudad: 'city',
  correo_contacto: 'email',
  telefono: 'phone',
  direccion: 'address',
  'redes.whatsapp': 'whatsapp',
  'redes.instagram': 'instagram',
  'redes.facebook': 'facebook',
  'redes.tiktok': 'tiktok',
  logo: 'logo',
  banner: 'banner',
})

// The networks are a list, so they are reached by their id.
const fields = {
  name: useTemplateRef<InstanceType<typeof TextField>>('nameField'),
  city: useTemplateRef<InstanceType<typeof TextField>>('cityField'),
  email: useTemplateRef<InstanceType<typeof TextField>>('emailField'),
  phone: useTemplateRef<InstanceType<typeof TextField>>('phoneField'),
  address: useTemplateRef<InstanceType<typeof TextField>>('addressField'),
  logo: useTemplateRef<InstanceType<typeof ImageField>>('logoField'),
  banner: useTemplateRef<InstanceType<typeof ImageField>>('bannerField'),
}

/** Puts what the API holds into the form. */
function fill(): void {
  const data = store.business
  if (data === null) return
  name.value = data.nombre
  city.value = data.ciudad
  email.value = data.correo_contacto ?? ''
  phone.value = data.telefono
  address.value = data.direccion ?? ''
  for (const network of NETWORK_ORDER) links[network] = data.redes[network] ?? ''
  listed.value = store.features?.aparece_en_directorio ?? false
  logo.value = null
  banner.value = null
}

async function load(): Promise<void> {
  await store.loadPublic(shopId.value)
  if (store.publicStatus === 'ready') fill()
}
onMounted(load)

function touched(field: Field): void {
  errors.clearField(field)
  done.value = false
}

/** The quick check before the trip; the API still has the last word. */
function check(): boolean {
  errors.clear()
  if (name.value.trim() === '') errors.setField('name', 'Escriba el nombre de la barbería.')
  if (city.value.trim() === '') errors.setField('city', 'Escriba la ciudad.')
  if (email.value.trim() !== '' && emailProblem(email.value) !== '') {
    errors.setField('email', EMAIL_PROBLEM)
  }
  if (phone.value.trim() === '') errors.setField('phone', 'Escriba el teléfono de contacto.')
  for (const network of NETWORK_ORDER) {
    const problem = socialProblem(network, links[network])
    if (problem !== '') errors.setField(network, problem)
  }
  return Object.keys(errors.fieldErrors.value).length === 0
}

async function focusFirstError(): Promise<void> {
  await nextTick()
  const first = errors.firstField(FIELD_ORDER)
  if (first === undefined) return
  if (first !== 'logo' && first !== 'banner' && NETWORK_ORDER.includes(first as SocialNetwork)) {
    document.getElementById(`social-${first}`)?.focus()
    return
  }
  const target = fields[first as keyof typeof fields].value
  if (target !== null && 'focus' in target) target.focus()
  else if (target !== null) target.pick()
}

/** Only what differs from what the API holds; an empty optional field is sent as `null`. */
function businessPatch(): BusinessPatch {
  const current = store.business!
  const patch: BusinessPatch = {}
  const optional = (value: string): string | null => (value.trim() === '' ? null : value.trim())
  if (name.value.trim() !== current.nombre) patch.nombre = name.value.trim()
  if (city.value.trim() !== current.ciudad) patch.ciudad = city.value.trim()
  if (phone.value.trim() !== current.telefono) patch.telefono = phone.value.trim()
  if (email.value.trim() !== (current.correo_contacto ?? '')) {
    patch.correo_contacto = optional(email.value)
  }
  if (address.value.trim() !== (current.direccion ?? '')) patch.direccion = optional(address.value)
  const networks: NonNullable<BusinessPatch['redes']> = {}
  for (const network of NETWORK_ORDER) {
    if (links[network].trim() !== (current.redes[network] ?? '')) {
      networks[network] = optional(links[network])
    }
  }
  if (Object.keys(networks).length > 0) patch.redes = networks
  return patch
}

function steps(): SaveStep[] {
  const list: SaveStep[] = []
  if (Object.keys(businessPatch()).length > 0) {
    list.push({
      label: 'los datos del negocio',
      run: async () => {
        const saved = await store.updateBusiness(shopId.value, businessPatch())
        activeShop.rename(shopId.value, saved.nombre)
      },
    })
  }
  if (listed.value !== (store.features?.aparece_en_directorio ?? false)) {
    list.push({
      label: 'su lugar en el directorio',
      run: async () => {
        await store.updateFeatures(shopId.value, { aparece_en_directorio: listed.value })
      },
    })
  }
  for (const [kind, file, label] of [
    ['logo', logo, 'el logo'],
    ['banner', banner, 'el banner'],
  ] as const) {
    if (file.value !== null) {
      list.push({
        label,
        run: async () => {
          await store.uploadImage(shopId.value, kind, file.value!)
          file.value = null
        },
      })
    }
  }
  return list
}

async function submit(): Promise<void> {
  if (saving.value) return
  done.value = false
  if (!check()) {
    await focusFirstError()
    return
  }
  saving.value = true
  const outcome = await runSteps(steps())
  saving.value = false
  if (outcome.failed === null) {
    fill()
    done.value = true
    return
  }
  const { error } = outcome.failed
  // A 401 closes the session in the client; there is nothing to tell here.
  if (isApiError(error) && error.status === 401) return
  if (isApiError(error)) errors.setFromApiError(error)
  const hasFieldErrors = Object.keys(errors.fieldErrors.value).length > 0
  if (outcome.saved.length > 0) errors.generalError.value = partialMessage(outcome)
  else if (!hasFieldErrors) {
    errors.generalError.value = `No pudimos guardar los datos. ${partialMessage(outcome)}`
  } else errors.generalError.value = ''
  await focusFirstError()
}

const logoPreview = useObjectUrl(logo)
const bannerPreview = useObjectUrl(banner)
const previewLogo = computed(() => logoPreview.value ?? store.images.logo ?? undefined)
const previewBanner = computed(() => bannerPreview.value ?? store.images.banner ?? undefined)
const previewNote = computed(() => {
  const withBanner = previewBanner.value !== undefined
  if (withBanner && previewLogo.value !== undefined) return 'Con banner.'
  if (withBanner) return 'Con banner · sin logo se usa el monograma con sus iniciales.'
  if (previewLogo.value !== undefined) return 'Sin banner: cabecera compacta.'
  return 'Sin banner: cabecera compacta · sin logo se usa el monograma con sus iniciales.'
})
const publicPath = computed(() => `/b/${store.business?.enlace ?? ''}`)
</script>

<template>
  <section aria-labelledby="public-title">
    <h2 id="public-title" class="sr-only">Datos públicos</h2>
    <SkeletonBlock
      v-if="store.publicStatus === 'loading' || store.publicStatus === 'idle'"
      shape="row"
      :count="6"
      label="Cargando los datos de la barbería"
    />
    <ErrorState
      v-else-if="store.publicStatus === 'error'"
      message="No pudimos cargar los datos de la barbería"
      description="Revise su conexión e inténtelo de nuevo."
      @retry="load"
    />
    <form
      v-else
      class="flex flex-col gap-8"
      novalidate
      aria-labelledby="public-title"
      @submit.prevent="submit"
    >
      <NoticeBanner v-if="done">Datos guardados. Sus clientes ya ven los cambios.</NoticeBanner>
      <NoticeBanner v-if="errors.generalError.value" variant="error">{{
        errors.generalError.value
      }}</NoticeBanner>

      <section class="flex flex-col gap-5" aria-labelledby="business-title">
        <h3 id="business-title" class="text-lg font-medium">Datos del negocio</h3>
        <div class="flex flex-col gap-2">
          <TextField
            ref="nameField"
            v-model="name"
            label="Nombre"
            autocomplete="off"
            maxlength="120"
            aria-describedby="name-help"
            :error="errors.fieldErrors.value.name"
            @input="touched('name')"
          />
          <p id="name-help" class="text-sm text-fg-muted">
            Cambiar el nombre no cambia su enlace público.
          </p>
        </div>
        <TextField
          ref="cityField"
          v-model="city"
          label="Ciudad"
          autocomplete="off"
          maxlength="80"
          :error="errors.fieldErrors.value.city"
          @input="touched('city')"
        />
        <div class="flex flex-col gap-2">
          <TextField
            ref="emailField"
            v-model="email"
            label="Correo de contacto"
            type="email"
            autocomplete="off"
            maxlength="150"
            aria-describedby="email-help"
            :error="errors.fieldErrors.value.email"
            @input="touched('email')"
          />
          <p id="email-help" class="text-sm text-fg-muted">
            Es el correo del negocio, distinto del de su cuenta.
          </p>
        </div>
        <TextField
          ref="phoneField"
          v-model="phone"
          label="Teléfono"
          type="tel"
          autocomplete="off"
          maxlength="30"
          :error="errors.fieldErrors.value.phone"
          @input="touched('phone')"
        />
        <div class="flex flex-col gap-2">
          <TextField
            ref="addressField"
            v-model="address"
            label="Dirección"
            autocomplete="off"
            maxlength="200"
            aria-describedby="address-help"
            :error="errors.fieldErrors.value.address"
            @input="touched('address')"
          />
          <p id="address-help" class="text-sm text-fg-muted">
            La ve el cliente con los enlaces a Google Maps y Waze.
          </p>
        </div>
      </section>

      <section class="flex flex-col gap-5" aria-labelledby="networks-title">
        <div class="flex flex-col gap-1">
          <h3 id="networks-title" class="text-lg font-medium">Redes sociales</h3>
          <p class="text-sm text-fg-muted">Opcional. Solo se muestran las que complete.</p>
        </div>
        <TextField
          v-for="network in NETWORK_ORDER"
          :key="network"
          :id="`social-${network}`"
          v-model="links[network]"
          :label="SOCIAL_NETWORKS[network].name"
          type="url"
          inputmode="url"
          autocomplete="off"
          :maxlength="MAX_SOCIAL_LENGTH"
          :placeholder="SOCIAL_NETWORKS[network].placeholder"
          :error="errors.fieldErrors.value[network]"
          @input="touched(network)"
        />
      </section>

      <section class="flex flex-col gap-5" aria-labelledby="images-title">
        <h3 id="images-title" class="text-lg font-medium">Imagen</h3>
        <!-- No «Quitar»: the API has no call to remove an image, only to replace it. -->
        <div class="flex flex-wrap items-start gap-x-12 gap-y-8">
          <ImageField
            ref="logoField"
            shape="circle"
            v-model="logo"
            label="Logo (opcional)"
            hint="JPG, PNG o WebP de hasta 2 MB."
            :current-url="store.images.logo"
            :error="errors.fieldErrors.value.logo"
            :disabled="saving"
            @update:model-value="touched('logo')"
          />
          <ImageField
            ref="bannerField"
            v-model="banner"
            label="Banner (opcional)"
            hint="Al menos 1.200 × 400 px. JPG, PNG o WebP de hasta 2 MB."
            shape="banner"
            class="min-w-0 flex-1 basis-60"
            subject="El banner"
            :min-size="MIN_BANNER"
            :current-url="store.images.banner"
            :error="errors.fieldErrors.value.banner"
            :disabled="saving"
            @update:model-value="touched('banner')"
          />
        </div>
      </section>

      <section class="flex flex-col gap-3" aria-labelledby="preview-title">
        <h3 id="preview-title" class="text-lg font-medium">Así lo ven sus clientes</h3>
        <ShopHeaderPreview>
          <ShopHeader
            class="border-b-0!"
            wide
            name-tag="p"
            :name="name"
            :city="city"
            :phone="phone"
            :address="address"
            :logo-url="previewLogo"
            :banner-url="previewBanner"
            :whatsapp="links.whatsapp"
            :instagram="links.instagram"
            :facebook="links.facebook"
            :tiktok="links.tiktok"
            :email="email"
          />
        </ShopHeaderPreview>
        <p class="text-sm text-fg-muted">Vista previa: no se puede interactuar.</p>
        <p class="text-sm text-fg-muted">{{ previewNote }}</p>
        <p class="text-sm">
          <span class="text-fg-muted">Enlace público</span>
          <span class="ml-2 font-mono" data-testid="public-link">{{ publicPath }}</span>
        </p>
      </section>

      <SwitchField
        v-model="listed"
        label="Aparecer en el directorio"
        description="Si lo activa, su barbería aparece en el directorio de barberías. Su enlace público funciona igual en ambos casos."
        @update:model-value="done = false"
      />

      <div>
        <BaseButton type="submit" size="large" :loading="saving">Guardar datos</BaseButton>
      </div>
    </form>
  </section>
</template>
