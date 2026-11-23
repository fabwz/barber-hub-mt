<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import BaseButton from '@/components/BaseButton.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import ChangePasswordForm from '@/components/ChangePasswordForm.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import { useDestinationAfterLogin } from '@/composables/useDestinationAfterLogin'
import { useSessionStore } from '@/stores/session'

// The mandatory change of a temporary password (UP-04, RF-40). The router sends the person here, and
// keeps them here, while the account still has the temporary password: no navbar, only «Cerrar sesión».
const router = useRouter()
const session = useSessionStore()
const destinationAfterLogin = useDestinationAfterLogin()

const changed = ref(false)
const continuing = ref(false)
const signingOut = ref(false)

/** Goes where the person would have gone after signing in: the shop selector for staff, «/» for the rest. */
async function proceed(): Promise<void> {
  if (continuing.value) return
  continuing.value = true
  try {
    await router.replace(await destinationAfterLogin())
  } finally {
    continuing.value = false
  }
}

async function logout(): Promise<void> {
  if (signingOut.value) return
  signingOut.value = true
  try {
    await session.logout()
    await router.replace('/')
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center justify-between gap-2 px-5 md:px-8">
      <BrandLogo />
      <BaseButton variant="secondary" :loading="signingOut" @click="logout">
        Cerrar sesión
      </BaseButton>
    </header>
    <main class="px-5 pt-4 pb-10 md:px-8 md:pt-12">
      <div class="mx-auto w-full max-w-97.5 md:max-w-110">
        <template v-if="!changed">
          <h1 class="text-2xl">Cambie su contraseña temporal</h1>
          <p class="mt-2 text-fg-muted">
            Su cuenta se creó con una contraseña temporal. Por su seguridad, elija una contraseña
            nueva antes de continuar. Mientras no la cambie, no podrá usar otras pantallas de
            BarberHub.
          </p>
          <div class="mt-8">
            <ChangePasswordForm
              current-label="Contraseña temporal"
              current-required="Escriba su contraseña temporal."
              @changed="changed = true"
            />
          </div>
        </template>

        <template v-else>
          <h1 class="text-2xl">Contraseña actualizada</h1>
          <NoticeBanner variant="success" class="mt-6">
            Su contraseña se actualizó. Ya puede usar BarberHub.
          </NoticeBanner>
          <BaseButton size="large" class="mt-8 w-full" :loading="continuing" @click="proceed">
            {{ continuing ? 'Continuando' : 'Continuar' }}
          </BaseButton>
        </template>
      </div>
    </main>
  </div>
</template>
