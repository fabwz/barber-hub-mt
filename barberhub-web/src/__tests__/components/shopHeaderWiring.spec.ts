import { describe, expect, it } from 'vitest'

// The seven screens of the customer that show the header of the shop take it from the cover of the shop.
const views = import.meta.glob('/src/views/*.vue', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const WITH_HEADER = [
  'ShopView',
  'PaymentView',
  'BookingConfirmedView',
  'BookingDetailView',
  'MyBookingsView',
  'VoucherPurchaseView',
  'VouchersView',
]

describe('the header of the shop on the customer screens', () => {
  it.each(WITH_HEADER)('%s builds it from the real data of the shop', (name) => {
    const source = views[`/src/views/${name}.vue`]!
    expect(source).toContain('<ShopHeader')
    expect(source).toContain('shopHeaderProps(')
  })

  it('no screen keeps the example data of the design', () => {
    for (const [path, source] of Object.entries(views)) {
      expect(source, path).not.toContain('HEADER_EXAMPLE')
    }
  })
})
