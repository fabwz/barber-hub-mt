import { describe, expect, it } from 'vitest'

import { shopHeaderProps } from '@/composables/shopHeaderProps'

const COVER = {
  id: 'x',
  nombre: 'Pura Navaja',
  ciudad: 'San José',
  telefono: '2222-3333',
  lista_espera_activa: true,
}

describe('shopHeaderProps', () => {
  it('passes the logo, the banner, the address, the email and the networks of the cover', () => {
    const props = shopHeaderProps({
      ...COVER,
      direccion: 'Avenida Central',
      correo_contacto: 'hola@puranavaja.cr',
      logo: '/api/v1/archivos/l.png',
      banner: '/api/v1/archivos/b.webp',
      redes: { instagram: 'https://instagram.com/puranavaja' },
    })
    expect(props).toMatchObject({
      name: 'Pura Navaja',
      city: 'San José',
      phone: '2222-3333',
      address: 'Avenida Central',
      email: 'hola@puranavaja.cr',
      logoUrl: '/api/v1/archivos/l.png',
      bannerUrl: '/api/v1/archivos/b.webp',
      instagram: 'https://instagram.com/puranavaja',
    })
    expect(props.whatsapp).toBeUndefined()
  })

  it('leaves out what the API leaves out, so the header shows the monogram and the compact form', () => {
    const props = shopHeaderProps(COVER)
    expect(props.logoUrl).toBeUndefined()
    expect(props.bannerUrl).toBeUndefined()
    expect(props.address).toBeUndefined()
    expect(props.email).toBeUndefined()
  })
})
