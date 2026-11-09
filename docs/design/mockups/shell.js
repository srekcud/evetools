const ICONS = {
  home: '<path d="M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z"/>',
  wallet: '<path d="M3 7h15a3 3 0 013 3v8a2 2 0 01-2 2H5a2 2 0 01-2-2zM3 7l12-3v3M16 14h2"/>',
  industry: '<path d="M3 21V10l6 3V10l6 3V6l6-3v18zM7 17h2M12 17h2M17 17h2"/>',
  group: '<path d="M16 19v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1M9 10a3 3 0 100-6 3 3 0 000 6zM22 19v-1a4 4 0 00-3-3.87M16 4.13a3 3 0 010 5.75"/>',
  planet: '<circle cx="12" cy="12" r="6"/><path d="M3.5 15.5c-1 2 .5 3 4 2.2 3-.7 7-2.8 10-5.2 3-2.5 4.2-4.8 3-5.8-.8-.7-2.6-.4-5 .7"/>',
  appraisal: '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 13h6M9 17h4"/>',
  market: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  box: '<path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/>',
  users: '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/>',
  cog: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
  bell: '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
  chevron: '<path d="M6 9l6 6 6-6"/>',
  chevronRight: '<path d="M9 6l6 6-6 6"/>',
  alert: '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0zM12 9v4M12 17h.01"/>',
  x: '<path d="M18 6L6 18M6 6l12 12"/>',
  check: '<path d="M20 6L9 17l-5-5"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  refresh: '<path d="M21 12a9 9 0 01-15.5 6.2L3 16M3 12a9 9 0 0115.5-6.2L21 8M21 3v5h-5M3 21v-5h5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  more: '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
  sort: '<path d="M7 15l5 5 5-5M7 9l5-5 5 5"/>',
  sortDown: '<path d="M7 10l5 5 5-5"/>',
  copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
  external: '<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/>',
  help: '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 3-3 3M12 17h.01"/>',
  filter: '<path d="M22 3H2l8 9.5V19l4 2v-8.5z"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
  menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
  pickaxe: '<path d="M14 4l6 6M4 20l9-9M10 4c4 0 8 2 10 6"/>',
}

function icon(name, extra = '') {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ${extra}>${ICONS[name]}</svg>`
}

const NAV = [
  { section: null, items: [
    { id: 'dashboard', label: 'Tableau de bord', icon: 'home', href: 'dashboard.html' },
  ] },
  { section: 'Production', items: [
    { id: 'industry', label: 'Industrie', icon: 'industry', href: 'industry.html' },
    { id: 'group', label: 'Industrie de groupe', icon: 'group', href: '#' },
    { id: 'pi', label: 'Planétaire (PI)', icon: 'planet', href: '#', count: 2 },
  ] },
  { section: 'Économie', items: [
    { id: 'market', label: 'Marché', icon: 'market', href: '#' },
    { id: 'appraisal', label: 'Évaluateur', icon: 'appraisal', href: '#' },
    { id: 'ledger', label: 'Revenus', icon: 'wallet', href: '#' },
    { id: 'assets', label: 'Inventaire', icon: 'box', href: '#' },
  ] },
  { section: 'Compte', items: [
    { id: 'characters', label: 'Personnages', icon: 'users', href: '#', count: 1 },
    { id: 'settings', label: 'Paramètres', icon: 'cog', href: '#' },
  ] },
]

const LONG_ACCOUNT = { name: 'Alexandrine Vasquez-Okonkwo Delacroix', corp: 'Interstellar Kaalakiota Heavy Industries Logistics', initials: 'AV' }
const SHORT_ACCOUNT = { name: 'Aria Valtorr', corp: 'Kaalakiota Logistics', initials: 'AV' }

function renderShell({ page, crumbs }) {
  const nav = NAV.map(group => `
    <div class="nav-section">
      ${group.section ? `<div class="nav-heading">${group.section}</div>` : ''}
      ${group.items.map(item => `
        <a href="${item.href}" ${item.id === page ? 'aria-current="page"' : ''}>
          ${icon(item.icon)}<span>${item.label}</span>
          ${item.count ? `<span class="count" aria-label="${item.count} élément(s) à traiter">${item.count}</span>` : ''}
        </a>`).join('')}
    </div>`).join('')

  const breadcrumb = crumbs.map((crumb, index) => index === crumbs.length - 1
    ? `<span aria-current="page" ${crumb.long ? `data-long="${crumb.long}"` : ''}>${crumb.label}</span>`
    : `<a href="${crumb.href}">${crumb.label}</a>${icon('chevronRight', 'width="14" height="14"')}`).join('')

  document.getElementById('sidebar').innerHTML = `
    <div class="brand"><div class="brand-mark hex">ET</div><span class="brand-name">EVE <span>Tools</span></span></div>
    <nav class="nav" aria-label="Navigation principale">${nav}</nav>
    <div class="sidebar-foot"><a href="#">v0.14 · nouveautés</a><a href="#">Aide</a></div>`

  document.getElementById('topbar').innerHTML = `
    <button class="icon-btn" id="menu-btn" aria-label="Ouvrir la navigation" style="display:none">${icon('menu')}</button>
    <nav class="breadcrumb hide-mobile" aria-label="Fil d'Ariane">${breadcrumb}</nav>
    <button class="search-trigger">
      ${icon('search')}<span>Rechercher objet, projet, système…</span><span class="kbd">Ctrl K</span>
    </button>
    <span class="status-pill" title="Serveur Tranquility joignable. Dernière vérification il y a 2 min.">
      <span class="led" aria-hidden="true"></span>Tranquility <span class="num">24 812</span>
    </span>
    <button class="icon-btn" aria-label="Notifications, 3 non lues">${icon('bell')}<span class="dot" aria-hidden="true">3</span></button>
    <button class="account" aria-haspopup="menu" id="account-btn">
      <span class="portrait hex" aria-hidden="true">AV</span>
      <span class="who"><b id="account-name"></b><small id="account-corp"></small></span>
      ${icon('chevron', 'width="14" height="14"')}
    </button>`

  const setAccount = account => {
    document.getElementById('account-name').textContent = account.name
    document.getElementById('account-corp').textContent = account.corp
  }
  setAccount(SHORT_ACCOUNT)

  if (window.matchMedia('(max-width: 860px)').matches) {
    document.getElementById('menu-btn').style.display = 'grid'
  }

  const tools = document.createElement('div')
  tools.className = 'mock-tools'
  const annotToggle = document.createElement('button')
  const longToggle = document.createElement('button')
  annotToggle.className = longToggle.className = 'btn btn-sm'
  annotToggle.type = longToggle.type = 'button'
  tools.append(longToggle, annotToggle)
  document.body.appendChild(tools)

  const syncAnnot = () => {
    const on = document.body.classList.contains('show-annot')
    annotToggle.textContent = on ? 'Annotations : on' : 'Annotations : off'
    annotToggle.setAttribute('aria-pressed', String(on))
  }
  annotToggle.addEventListener('click', () => { document.body.classList.toggle('show-annot'); syncAnnot() })

  const longNodes = [...document.querySelectorAll('[data-long]')]
  longNodes.forEach(node => { node.dataset.short = node.textContent })
  let isLong = new URLSearchParams(location.search).has('long')
  const syncLong = () => {
    longNodes.forEach(node => { node.textContent = isLong ? node.dataset.long : node.dataset.short })
    setAccount(isLong ? LONG_ACCOUNT : SHORT_ACCOUNT)
    longToggle.textContent = isLong ? 'Noms longs : on' : 'Noms longs : off'
    longToggle.setAttribute('aria-pressed', String(isLong))
    requestAnimationFrame(addTitlesToTruncatedText)
  }
  longToggle.addEventListener('click', () => { isLong = !isLong; syncLong() })

  document.body.classList.toggle('show-annot', !new URLSearchParams(location.search).has('clean'))
  syncAnnot()
  syncLong()
}

function addTitlesToTruncatedText() {
  document.querySelectorAll('.truncate, .clamp-2, .account .who b, .account .who small, .nav a > span, .breadcrumb [aria-current]').forEach(node => {
    const isCut = node.scrollWidth > node.clientWidth + 1 || node.scrollHeight > node.clientHeight + 1
    if (isCut) node.title = node.textContent.trim()
    else node.removeAttribute('title')
  })
}
window.addEventListener('resize', () => requestAnimationFrame(addTitlesToTruncatedText))
