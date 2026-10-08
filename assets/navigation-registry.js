/* TAMASYA domain + additive module navigation registry.
 * Pure configuration + pure permission helpers. No DOM access and no React dependency.
 * This is the stable boundary for future domain/module extraction.
 */
const TAMASYA_NAV_REGISTRY_VERSION = '20261008-r14-dbfix';

const TAMASYA_DOMAIN_NAV = Object.freeze({
  frontoffice: Object.freeze({
    id: 'frontoffice', label: 'Front Office', icon: '🛎',
    members: Object.freeze([
      Object.freeze({ route: 'rooms', label: 'Kamar' }),
      Object.freeze({ route: 'reservations', permission: 'rooms', label: 'Reservasi' }),
      Object.freeze({ route: 'support', label: 'Layanan Tamu / Bantuan' })
    ])
  }),
  operations: Object.freeze({
    id: 'operations', label: 'Operasional', icon: '⚙',
    members: Object.freeze([
      Object.freeze({ route: 'operations', label: 'Pusat Operasional & Kontrol' }),
      Object.freeze({ route: 'inventory', label: 'Aset & Stok' }),
      Object.freeze({ route: 'telegram', label: 'Tugas & Notifikasi' })
    ])
  }),
  hr: Object.freeze({
    id: 'hr', label: 'SDM', icon: '👥',
    members: Object.freeze([
      Object.freeze({ route: 'staff', label: 'Karyawan' }),
      Object.freeze({ route: 'attendance', label: 'Absensi' }),
      Object.freeze({ route: 'leaves', label: 'Cuti' }),
      Object.freeze({ route: 'savings', label: 'Simpanan Karyawan' })
    ])
  }),
  system: Object.freeze({
    id: 'system', label: 'Sistem', icon: '⚙',
    members: Object.freeze([
      Object.freeze({ workspace: 'finance-catalog', label: 'Kategori & Subkategori', roles: Object.freeze(['admin','manager','finance','owner']) }),
      Object.freeze({ route: 'config', label: 'Integrasi / API' }),
      Object.freeze({ route: 'db_config', label: 'Database' }),
      Object.freeze({ route: 'local_connect', label: 'Koneksi Lokal', roles: Object.freeze(['admin','manager','owner']) })
    ])
  })
});

const TAMASYA_DOMAIN_ORDER = Object.freeze(['frontoffice','operations','hr','system']);

/*
 * Additive modules deliberately live outside the primary desktop-tab registry.
 * `permission` follows the backend hasDesktopTabAccess contract:
 *   - explicit desktopTabs.<permission> wins when present;
 *   - otherwise role membership is the fallback.
 *
 * `showWhenLocked` separates discoverability from activation. A locked card is
 * rendered without an href, so an OFF feature flag never becomes UI-only security.
 */
const TAMASYA_MODULE_DOCK = Object.freeze({
  memo: Object.freeze({
    id: 'memo',
    elementId: 'tamasya-memo-menu',
    href: './internal-memo.html',
    kind: 'memo',
    icon: '📝',
    title: 'Memo Internal',
    subtitle: 'Catatan Admin & Keuangan',
    aria: 'Buka Memo Internal TAMASYA',
    roles: Object.freeze(['admin','finance','owner']),
    requiredAnyTabs: Object.freeze(['finance','report','staff']),
    showWhenLocked: false
  }),
  pos: Object.freeze({
    id: 'pos',
    elementId: 'tamasya-pos-menu',
    href: './pos.html',
    kind: 'pos',
    icon: '🛒',
    title: 'POS & Minibar',
    subtitle: 'Penjualan harian & stok',
    aria: 'Buka POS dan Minibar TAMASYA',
    permission: 'pos',
    roles: Object.freeze(['admin','manager','receptionist','finance','owner']),
    showWhenLocked: false
  }),
  growth: Object.freeze({
    id: 'growth',
    elementId: 'tamasya-growth-menu',
    href: './growth-suite.html',
    kind: 'growth',
    icon: '📈',
    title: 'Growth Suite',
    subtitle: 'Revenue, rate & commercial',
    aria: 'Buka Growth Suite TAMASYA',
    roles: Object.freeze(['admin','manager','finance','owner']),
    requiredAnyTabs: Object.freeze(['rooms','finance','report','operations','inventory']),
    requiredFeatures: Object.freeze(['growthSuiteEnabled']),
    showWhenLocked: true,
    lockedLabel: 'Terkunci',
    lockedReason: 'Belum diaktifkan untuk staging ini'
  }),
  enterprise: Object.freeze({
    id: 'enterprise',
    elementId: 'tamasya-enterprise-menu',
    href: './enterprise-suite.html',
    kind: 'enterprise',
    icon: '🏢',
    title: 'Enterprise Suite',
    subtitle: 'Folio, AP, CRM & kontrol',
    aria: 'Buka Enterprise Suite TAMASYA',
    roles: Object.freeze(['admin','manager','finance','owner']),
    requiredAnyTabs: Object.freeze(['rooms','finance','report','operations','inventory','config']),
    requiredFeatures: Object.freeze(['growthSuiteEnabled','enterpriseCompletionEnabled']),
    showWhenLocked: true,
    lockedLabel: 'Terkunci',
    lockedReason: 'Memerlukan Growth Suite dan Enterprise Completion aktif'
  })
});

const TAMASYA_MODULE_ORDER = Object.freeze(['pos','memo','growth','enterprise']);

function tamasyaVisibleDomainMembers(group, allowedTabs, role) {
  const allowed = Array.isArray(allowedTabs) ? allowedTabs : [];
  const currentRole = String(role || '').trim().toLowerCase();
  const members = group && Array.isArray(group.members) ? group.members : [];
  return members.filter((member) => {
    if (member.roles && !member.roles.includes(currentRole)) return false;
    if (member.workspace) return !member.roles || member.roles.includes(currentRole);
    const permission = member.permission || member.route;
    return Boolean(permission && allowed.includes(permission));
  });
}

function tamasyaDomainVisible(group, allowedTabs, role) {
  return tamasyaVisibleDomainMembers(group, allowedTabs, role).length > 0;
}

function tamasyaExplicitDesktopPermission(permissions, key) {
  if (!permissions || typeof permissions !== 'object') return null;
  const desktopTabs = permissions.desktopTabs;
  if (!desktopTabs || typeof desktopTabs !== 'object') return null;
  if (!Object.prototype.hasOwnProperty.call(desktopTabs, key)) return null;
  return Boolean(desktopTabs[key]);
}

function tamasyaModuleDockState(moduleDefinition, context = {}) {
  const module = moduleDefinition || {};
  const role = String(context.role || '').trim().toLowerCase();
  const allowedTabs = Array.isArray(context.allowedTabs) ? context.allowedTabs : [];
  const allowedSet = new Set(allowedTabs);
  const ownerRead = role === 'owner';
  const features = context.features && typeof context.features === 'object' ? context.features : {};
  const roleAllowed = !Array.isArray(module.roles) || module.roles.includes(role);
  if (!roleAllowed) return Object.freeze({ visible:false, enabled:false, locked:false, reason:'role' });

  if (module.permission && !ownerRead) {
    const explicit = tamasyaExplicitDesktopPermission(context.permissions, module.permission);
    if (explicit === false) return Object.freeze({ visible:false, enabled:false, locked:false, reason:'permission' });
  }

  if (Array.isArray(module.requiredAnyTabs) && module.requiredAnyTabs.length > 0) {
    const hasRequiredAccess = ownerRead || module.requiredAnyTabs.some((tab) => allowedSet.has(tab));
    if (!hasRequiredAccess) return Object.freeze({ visible:false, enabled:false, locked:false, reason:'related-tab-access' });
  }

  const requiredFeatures = Array.isArray(module.requiredFeatures) ? module.requiredFeatures : [];
  const enabled = requiredFeatures.every((feature) => features[feature] === true);
  if (requiredFeatures.length === 0) {
    return Object.freeze({ visible:true, enabled:true, locked:false, reason:'enabled' });
  }
  if (enabled) return Object.freeze({ visible:true, enabled:true, locked:false, reason:'enabled' });
  if (module.showWhenLocked === true) return Object.freeze({ visible:true, enabled:false, locked:true, reason:'feature-locked' });
  return Object.freeze({ visible:false, enabled:false, locked:false, reason:'feature-off' });
}

function tamasyaVisibleModuleCards(context = {}) {
  return TAMASYA_MODULE_ORDER.flatMap((id) => {
    const module = TAMASYA_MODULE_DOCK[id];
    if (!module) return [];
    const state = tamasyaModuleDockState(module, context);
    return state.visible ? [Object.freeze({ ...module, ...state })] : [];
  });
}

export {
  TAMASYA_NAV_REGISTRY_VERSION,
  TAMASYA_DOMAIN_NAV,
  TAMASYA_DOMAIN_ORDER,
  TAMASYA_MODULE_DOCK,
  TAMASYA_MODULE_ORDER,
  tamasyaVisibleDomainMembers,
  tamasyaDomainVisible,
  tamasyaExplicitDesktopPermission,
  tamasyaModuleDockState,
  tamasyaVisibleModuleCards
};
