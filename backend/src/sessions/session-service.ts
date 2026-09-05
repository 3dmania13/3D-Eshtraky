import type { SubscriberRepository } from '../domain/contracts.js';
import type { SubscriberPrincipal } from '../domain/models.js';
import { asSafeNumber } from '../utils/values.js';

export class SessionService {
  constructor(private readonly repository: SubscriberRepository) {}

  async list(principal: SubscriberPrincipal) {
    const sessions = await this.repository.getSessions(principal.username, 100);
    return sessions.map((session) => ({
      session_id: session.sessionId,
      start_time: session.startTime.toISOString(),
      stop_time: session.stopTime?.toISOString() ?? null,
      duration_seconds: session.durationSeconds,
      upload_bytes: asSafeNumber(session.uploadBytes, 'upload_bytes'),
      download_bytes: asSafeNumber(session.downloadBytes, 'download_bytes'),
      framed_ip: session.framedIp,
      calling_station_id: session.callingStationId,
      network_identifier: session.networkIdentifier,
      is_active: session.isActive,
    }));
  }
}
