import { nextChunkOf } from '@/views/MiningSite/miningCompanion'
import {
  CHUNK_A,
  CHUNK_B,
  CHUNK_C_EXPLODED,
  CHUNK_READY,
  CHUNK_TIED_WITH_B,
  FREE_NOW,
  corporationsOf,
} from '../../components/mining-site/companionFreeFixtures'

describe('nextChunkOf', () => {
  it('returns the chunk arriving first among all corporations, ignoring the exploded ones, or null when none is left', () => {
    const corporations = corporationsOf([CHUNK_A, CHUNK_C_EXPLODED], [CHUNK_B])

    expect({
      closest: nextChunkOf(corporations, FREE_NOW)?.id ?? null,
      onlyExploded: nextChunkOf(corporationsOf([CHUNK_C_EXPLODED]), FREE_NOW),
      noCorporation: nextChunkOf([], FREE_NOW),
      noExtraction: nextChunkOf(corporationsOf([], []), FREE_NOW),
    }).toEqual({
      closest: CHUNK_B.id,
      onlyExploded: null,
      noCorporation: null,
      noExtraction: null,
    })
  })

  it('prefers a ready chunk to a coming one, drops a chunk exploded at the given instant whatever its stored status and keeps the entry order on a tie', () => {
    const staleStatusChunk = { ...CHUNK_C_EXPLODED, status: 'extracting' as const }

    expect({
      ready: nextChunkOf(corporationsOf([CHUNK_B], [CHUNK_READY]), FREE_NOW)?.id ?? null,
      stale: nextChunkOf(corporationsOf([staleStatusChunk, CHUNK_A]), FREE_NOW)?.id ?? null,
      tie: nextChunkOf(corporationsOf([CHUNK_B], [CHUNK_TIED_WITH_B]), FREE_NOW)?.id ?? null,
      tieReversed: nextChunkOf(corporationsOf([CHUNK_TIED_WITH_B], [CHUNK_B]), FREE_NOW)?.id ?? null,
    }).toEqual({
      ready: CHUNK_READY.id,
      stale: CHUNK_A.id,
      tie: CHUNK_B.id,
      tieReversed: CHUNK_TIED_WITH_B.id,
    })
  })
})
