import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, type components } from '@/api'

export type BusinessData = components['schemas']['DatosDeBarberia']
export type BusinessPatch = components['schemas']['ActualizarBarberia']
export type BillingPolicy = components['schemas']['Cobro']
export type BillingPatch = components['schemas']['ActualizarCobro']
export type Features = components['schemas']['Funciones']
export type FeaturesPatch = components['schemas']['ActualizarFunciones']
export type ShopImages = components['schemas']['ImagenesDeLaBarberia']
export type ImageKind = 'logo' | 'banner'

export type SettingsStatus = 'idle' | 'loading' | 'ready' | 'error'

/**
 * What the owner configures (RF-01, RF-08, RF-09, RF-46): the public data, the images, the charging policy and
 * the switches. The shop travels in the route of every call, never in the body (RD-18). Every action throws the
 * ApiError of the client; the screens decide what to tell the person.
 */
export const useSettingsStore = defineStore('settings', () => {
  const shopId = ref<string | null>(null)
  const business = ref<BusinessData | null>(null)
  const images = ref<ShopImages>({ logo: null, banner: null })
  const features = ref<Features | null>(null)
  const billing = ref<BillingPolicy | null>(null)
  const publicStatus = ref<SettingsStatus>('idle')
  const billingStatus = ref<SettingsStatus>('idle')

  // A newer load or a reset drops the answer of an older one.
  let publicGeneration = 0
  let billingGeneration = 0

  function enter(barberia: string): void {
    if (shopId.value === barberia) return
    shopId.value = barberia
    business.value = null
    images.value = { logo: null, banner: null }
    features.value = null
    billing.value = null
  }

  /** «Datos públicos»: the data, the switches and, from the public cover, the current logo and banner. */
  async function loadPublic(barberia: string): Promise<void> {
    enter(barberia)
    const current = ++publicGeneration
    publicStatus.value = 'loading'
    try {
      const path = { barberia }
      const [data, funciones] = await Promise.all([
        api.GET('/barberias/{barberia}/administracion/negocio', { params: { path } }),
        api.GET('/barberias/{barberia}/administracion/funciones', { params: { path } }),
      ])
      // The negocio call has no images: the public cover, by the link of the shop, brings them.
      const cover = await api.GET('/enlaces/{enlace}', {
        params: { path: { enlace: data.data!.data.enlace } },
      })
      if (current !== publicGeneration) return
      business.value = data.data!.data
      features.value = funciones.data!.data
      images.value = {
        logo: cover.data!.data.logo ?? null,
        banner: cover.data!.data.banner ?? null,
      }
      publicStatus.value = 'ready'
    } catch {
      if (current !== publicGeneration) return
      publicStatus.value = 'error'
    }
  }

  /** «Cobro y políticas»: the policy and the switches. */
  async function loadBilling(barberia: string): Promise<void> {
    enter(barberia)
    const current = ++billingGeneration
    billingStatus.value = 'loading'
    try {
      const path = { barberia }
      const [cobro, funciones] = await Promise.all([
        api.GET('/barberias/{barberia}/administracion/cobro', { params: { path } }),
        api.GET('/barberias/{barberia}/administracion/funciones', { params: { path } }),
      ])
      if (current !== billingGeneration) return
      billing.value = cobro.data!.data
      features.value = funciones.data!.data
      billingStatus.value = 'ready'
    } catch {
      if (current !== billingGeneration) return
      billingStatus.value = 'error'
    }
  }

  async function updateBusiness(barberia: string, patch: BusinessPatch): Promise<BusinessData> {
    const { data } = await api.PATCH('/barberias/{barberia}/administracion/negocio', {
      params: { path: { barberia } },
      body: patch,
    })
    business.value = data!.data
    return data!.data
  }

  async function updateFeatures(barberia: string, patch: FeaturesPatch): Promise<Features> {
    const { data } = await api.PATCH('/barberias/{barberia}/administracion/funciones', {
      params: { path: { barberia } },
      body: patch,
    })
    features.value = data!.data
    return data!.data
  }

  async function updateBilling(barberia: string, patch: BillingPatch): Promise<BillingPolicy> {
    const { data } = await api.PATCH('/barberias/{barberia}/administracion/cobro', {
      params: { path: { barberia } },
      body: patch,
    })
    billing.value = data!.data
    return data!.data
  }

  /** Uploads or replaces the logo or the banner (SV-06, RF-46) as `multipart/form-data`; the API reads the real type. */
  async function uploadImage(barberia: string, kind: ImageKind, file: File): Promise<ShopImages> {
    const params = { path: { barberia } }
    const bodySerializer = () => {
      const form = new FormData()
      form.append(kind, file)
      return form
    }
    // The contract types the file as a string; the serializer builds the real form.
    const { data } =
      kind === 'logo'
        ? await api.POST('/barberias/{barberia}/administracion/imagenes/logo', {
            params,
            body: { logo: '' },
            bodySerializer,
          })
        : await api.POST('/barberias/{barberia}/administracion/imagenes/banner', {
            params,
            body: { banner: '' },
            bodySerializer,
          })
    images.value = data!.data
    return data!.data
  }

  function clear(): void {
    publicGeneration++
    billingGeneration++
    shopId.value = null
    business.value = null
    images.value = { logo: null, banner: null }
    features.value = null
    billing.value = null
    publicStatus.value = 'idle'
    billingStatus.value = 'idle'
  }

  return {
    shopId,
    business,
    images,
    features,
    billing,
    publicStatus,
    billingStatus,
    loadPublic,
    loadBilling,
    updateBusiness,
    updateFeatures,
    updateBilling,
    uploadImage,
    clear,
  }
})
