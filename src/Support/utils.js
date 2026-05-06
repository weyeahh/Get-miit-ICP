export function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function clamp(value, low, high) {
  return Math.max(low, Math.min(high, value));
}

export const DETAIL_FIELDS = ['domain', 'unitName', 'mainLicence', 'serviceLicence', 'natureName', 'leaderName', 'updateRecordTime'];
