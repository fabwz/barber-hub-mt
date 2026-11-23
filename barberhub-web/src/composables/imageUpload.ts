/**
 * Quick check of an image before it travels (SV-06). It only spares a trip: the API reads the type from the
 * content of the file and decides, so its answer always wins.
 */

export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const
export const IMAGE_ACCEPT = IMAGE_TYPES.join(',')
export const MAX_IMAGE_BYTES = 2 * 1024 * 1024

export const WRONG_TYPE_MESSAGE = 'Solo se aceptan imágenes JPG, PNG o WebP.'

/** «740 KB», «1,5 MB». */
export function fileSizeLabel(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`
  const megabytes = Math.round((bytes / (1024 * 1024)) * 10) / 10
  return `${String(megabytes).replace('.', ',')} MB`
}

/** The problem of a file as a message that names it and the way out, or an empty text when it is fine. */
export function imageProblem(file: Pick<File, 'type' | 'size'>): string {
  if (!(IMAGE_TYPES as readonly string[]).includes(file.type)) return WRONG_TYPE_MESSAGE
  if (file.size > MAX_IMAGE_BYTES) {
    return `El archivo pesa ${fileSizeLabel(file.size)}. Elija una imagen JPG, PNG o WebP de hasta 2 MB.`
  }
  return ''
}

/** «WebP», «PNG»… for the line under the file name. */
export function imageTypeLabel(type: string): string {
  if (type === 'image/jpeg') return 'JPG'
  if (type === 'image/png') return 'PNG'
  if (type === 'image/webp') return 'WebP'
  return type.split('/')[1]?.toUpperCase() ?? 'Archivo'
}
