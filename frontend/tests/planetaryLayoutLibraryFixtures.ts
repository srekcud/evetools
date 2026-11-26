import type { LibraryLayout } from '@/stores/planetaryLayoutLibrary'

export const LIBRARY_COOLANT: LibraryLayout = {
  id: 'library-coolant',
  name: 'Barren coolant line',
  summary: {
    planetTypeId: 2016,
    planetTypeName: 'Barren',
    commandCenterLevel: 4,
    buildings: { extractorControlUnits: 0, extractorHeads: 0, processors: 2, storages: 0, launchpads: 1 },
    links: 2,
    routes: 2,
    load: { cpu: 4100, powergrid: 1900 },
    products: [{ typeId: 9832, typeName: 'Coolant', tier: 'P2' }],
    extracted: [],
    skills: [{ name: 'Command Center Upgrades', level: 4 }],
  },
  gameTemplate: {
    CmdCtrLv: 4,
    Cmt: 'Original comment of the author',
    Diam: 0.5,
    L: [{ D: 2, Lv: 0, S: 1 }, { D: 3, Lv: 0, S: 1 }],
    P: [
      { H: 0, La: 1.25, Lo: -0.5, S: null, T: 2544 },
      { H: 0, La: 1.5, Lo: -0.25, S: 9832, T: 2474 },
      { H: 0, La: 1.75, Lo: 0.25, S: 9832, T: 2474 },
    ],
    Pln: 2016,
    R: [{ P: [2, 1], Q: 40, T: 3645 }, { P: [3, 1], Q: 40, T: 3645 }],
  },
  publishedAt: '2026-11-25T18:00:00+00:00',
  mine: false,
}

export const LIBRARY_ROBOTICS: LibraryLayout = {
  id: 'library-robotics',
  name: 'Lava robotics chain',
  summary: {
    planetTypeId: 2015,
    planetTypeName: 'Lava',
    commandCenterLevel: 5,
    buildings: { extractorControlUnits: 1, extractorHeads: 6, processors: 4, storages: 1, launchpads: 1 },
    links: 9,
    routes: 7,
    load: null,
    products: [{ typeId: 9848, typeName: 'Robotics', tier: 'P3' }, { typeId: 9830, typeName: 'Rocket Fuel', tier: 'P2' }],
    extracted: [{ typeId: 2073, typeName: 'Micro Organisms' }],
    skills: [{ name: 'Command Center Upgrades', level: 5 }],
  },
  gameTemplate: {
    CmdCtrLv: 5,
    Cmt: 'Lava notes',
    Diam: 0.75,
    L: [{ D: 2, Lv: 1, S: 1 }],
    P: [
      { H: 0, La: 0.5, Lo: 0.25, S: null, T: 2555 },
      { H: 4, La: 0.75, Lo: 0.5, S: 2073, T: 2848 },
    ],
    Pln: 2015,
    R: [{ P: [2, 1], Q: 120, T: 2073 }],
  },
  publishedAt: '2026-11-24T12:00:00+00:00',
  mine: false,
}

export const LIBRARY_OXYGEN: LibraryLayout = {
  id: 'library-oxygen',
  name: 'Barren oxygen pair',
  summary: {
    planetTypeId: 2016,
    planetTypeName: 'Barren',
    commandCenterLevel: 3,
    buildings: { extractorControlUnits: 0, extractorHeads: 0, processors: 1, storages: 0, launchpads: 1 },
    links: 1,
    routes: 1,
    load: { cpu: 2500, powergrid: 1200 },
    products: [{ typeId: 3683, typeName: 'Oxygen', tier: 'P1' }],
    extracted: [],
    skills: [{ name: 'Command Center Upgrades', level: 3 }],
  },
  gameTemplate: {
    CmdCtrLv: 3,
    Cmt: 'Oxygen',
    Diam: 0.25,
    L: [{ D: 2, Lv: 0, S: 1 }],
    P: [
      { H: 0, La: 1, Lo: 1, S: null, T: 2544 },
      { H: 0, La: 1.1, Lo: 1.1, S: 3683, T: 2469 },
    ],
    Pln: 2016,
    R: [{ P: [2, 1], Q: 20, T: 3683 }],
  },
  publishedAt: '2026-11-23T08:00:00+00:00',
  mine: true,
}
