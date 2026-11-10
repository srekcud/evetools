import { multibuyText } from '@/utils/multibuy'

describe('multibuyText', () => {
  it('writes one name, tab, quantity line per entry without header nor final newline, and nothing for an empty list', () => {
    const text = multibuyText([
      { typeName: 'Tritanium', quantity: 1_250_000 },
      { typeName: 'Morphite', quantity: 37 },
      { typeName: 'Nocxium', quantity: 4_800 },
    ])

    expect({ text, empty: multibuyText([]) }).toEqual({ text: 'Tritanium\t1250000\nMorphite\t37\nNocxium\t4800', empty: '' })
  })
})
