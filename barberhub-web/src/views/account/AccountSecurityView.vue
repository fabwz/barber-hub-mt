<script setup lang="ts">
import { ref } from 'vue'

import ChangePasswordForm from '@/components/ChangePasswordForm.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'

// «Mi cuenta · Seguridad» (RF-40): change the password with the current one. The API keeps this
// session open and closes the others, so nothing is signed out here.
const changed = ref(false)
</script>

<template>
  <section aria-labelledby="account-security-title">
    <h2 id="account-security-title" class="text-xl">Cambiar contraseña</h2>
    <p class="mt-2 text-fg-muted">Escriba su contraseña actual y elija una nueva.</p>

    <div class="mt-6 max-w-2xl">
      <ChangePasswordForm @changed="changed = true" />
      <NoticeBanner v-if="changed" variant="success" class="mt-4">
        Su contraseña se actualizó.
      </NoticeBanner>
    </div>
  </section>
</template>
