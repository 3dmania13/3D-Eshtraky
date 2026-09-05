import '../../../core/utils/json_reader.dart';

class SubscriberDevice {
  const SubscriberDevice({
    required this.id,
    required this.friendlyName,
    required this.macAddress,
    required this.ipAddress,
    required this.connectionStartedAt,
    required this.sessionDuration,
    required this.lastSeenAt,
    required this.currentSessionBytes,
    required this.isOnline,
  });

  final String id;
  final String friendlyName;
  final String macAddress;
  final String ipAddress;
  final DateTime connectionStartedAt;
  final Duration sessionDuration;
  final DateTime lastSeenAt;
  final int currentSessionBytes;
  final bool isOnline;

  SubscriberDevice copyWith({String? friendlyName}) => SubscriberDevice(
    id: id,
    friendlyName: friendlyName ?? this.friendlyName,
    macAddress: macAddress,
    ipAddress: ipAddress,
    connectionStartedAt: connectionStartedAt,
    sessionDuration: sessionDuration,
    lastSeenAt: lastSeenAt,
    currentSessionBytes: currentSessionBytes,
    isOnline: isOnline,
  );

  factory SubscriberDevice.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return SubscriberDevice(
      id: reader.string('id'),
      friendlyName: reader.string('friendly_name'),
      macAddress: reader.string('mac_address'),
      ipAddress: reader.string('ip_address'),
      connectionStartedAt: reader.dateTime('connection_started_at'),
      sessionDuration: Duration(
        seconds: reader.integer('session_duration_seconds'),
      ),
      lastSeenAt: reader.dateTime('last_seen_at'),
      currentSessionBytes: reader.integer('current_session_bytes'),
      isOnline: reader.boolean('is_online'),
    );
  }
}
