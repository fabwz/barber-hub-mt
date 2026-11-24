import type { components } from '@/api'

type PublicShop = components['schemas']['BarberiaPublica']

/**
 * What `ShopHeader` shows of a shop, from its public cover (RF-02, MT-08, MT-09): the name, the city, the phone,
 * the address, the networks, the contact email and the logo and banner when it has them. What the API leaves out
 * stays out, so the header shows the monogram without a logo and the compact header without a banner.
 */
export function shopHeaderProps(profile: PublicShop) {
  return {
    name: profile.nombre,
    city: profile.ciudad,
    phone: profile.telefono,
    address: profile.direccion ?? undefined,
    email: profile.correo_contacto ?? undefined,
    logoUrl: profile.logo ?? undefined,
    bannerUrl: profile.banner ?? undefined,
    whatsapp: profile.redes?.whatsapp,
    instagram: profile.redes?.instagram,
    facebook: profile.redes?.facebook,
    tiktok: profile.redes?.tiktok,
  }
}
