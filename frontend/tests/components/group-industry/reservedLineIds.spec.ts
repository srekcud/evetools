import { reservedLineIdsOfMine } from '@/components/group-industry/reservedLineIds'
import type { BomItem } from '@/stores/group-industry/types'

type Line = Pick<BomItem, 'id' | 'reservations'>

function reservation(isMine: boolean): BomItem['reservations'][number] {
  return { participantId: `participant-${isMine ? 'me' : 'other'}`, characterName: 'Brann Holt', quantity: 100, isMine, expiresAt: '2026-11-21T14:00:00+00:00' }
}

describe('reservedLineIdsOfMine', () => {
  it('lists the lines the user reserved and skips the lines reserved by others or by nobody', () => {
    const lines: Line[] = [
      { id: 'ram-line', reservations: [reservation(false), reservation(true)] },
      { id: 'ishtar-line', reservations: [reservation(false)] },
      { id: 'tritanium-line', reservations: [] },
      { id: 'morphite-line', reservations: [reservation(true)] },
    ]

    expect(reservedLineIdsOfMine(lines)).toEqual(['ram-line', 'morphite-line'])
  })

  it('lists nothing for a project without lines', () => {
    expect(reservedLineIdsOfMine([])).toEqual([])
  })
})
