// Canonicalize MAC formatting without conflating opaque RADIUS identifiers.
// Keep this expression shared by collection and the existing-device backfill.
export const radiusDeviceIdentitySql = `CASE
  WHEN TRIM(ra.callingstationid) REGEXP '^([0-9A-Fa-f]{12}|([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}|([0-9A-Fa-f]{4}[.]){2}[0-9A-Fa-f]{4})$'
  THEN UPPER(REPLACE(REPLACE(REPLACE(TRIM(ra.callingstationid),':',''),'-',''),'.',''))
  ELSE TRIM(ra.callingstationid) END`;
