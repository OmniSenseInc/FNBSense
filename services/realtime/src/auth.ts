import { readFileSync } from 'node:fs';
import jwt from 'jsonwebtoken';
import { ws as wsConfig } from './config.js';

/**
 * Verifikasi JWT KDS (F3b) — pola stateless yang sama dgn service Laravel:
 * verifikasi RS256 pakai PUBLIC key saja, tak pernah query IAM. Klaim yang
 * dipakai: tenant_id, outlet_id, role, sub.
 */

export interface Claims {
  sub: string;
  tenant_id: string;
  outlet_id: string;
  role: string;
}

// Baca public key sekali, lalu cache.
let cachedKey: string | null = null;
function publicKey(): string {
  if (cachedKey === null) {
    cachedKey = readFileSync(wsConfig.jwtPublicKeyPath, 'utf8');
  }
  return cachedKey;
}

export function verifyToken(token: string): Claims {
  const decoded = jwt.verify(token, publicKey(), { algorithms: ['RS256'] }) as jwt.JwtPayload;

  // KDS bekerja pada SATU outlet konkret; token tanpa outlet_id (mis. owner
  // belum terikat outlet) ditolak — bukan didiamkan.
  if (!decoded.tenant_id || !decoded.outlet_id || !decoded.sub) {
    throw new Error('klaim wajib (tenant_id/outlet_id/sub) tak lengkap');
  }

  return {
    sub: String(decoded.sub),
    tenant_id: String(decoded.tenant_id),
    outlet_id: String(decoded.outlet_id),
    role: String(decoded.role ?? ''),
  };
}
