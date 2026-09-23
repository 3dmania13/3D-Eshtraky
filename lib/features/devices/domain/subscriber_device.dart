import '../../../core/utils/json_reader.dart';

class SubscriberDevice {
  const SubscriberDevice({
    required this.id,
    required this.friendlyName,
    required this.speedSelection,
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
  final String? speedSelection;
  final String macAddress;
  final String ipAddress;
  final DateTime connectionStartedAt;
  final Duration sessionDuration;
  final DateTime lastSeenAt;
  final int currentSessionBytes;
  final bool isOnline;

  SubscriberDevice copyWith({
    String? friendlyName,
    String? speedSelection,
    bool clearSpeedSelection = false,
  }) => SubscriberDevice(
    id: id,
    friendlyName: friendlyName ?? this.friendlyName,
    speedSelection: clearSpeedSelection
        ? null
        : speedSelection ?? this.speedSelection,
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
      speedSelection: reader.nullableString('speed_selection'),
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

class DeviceSpeedUpdate {
  const DeviceSpeedUpdate({
    required this.selection,
    required this.appliedImmediately,
    required this.appliesOnNextConnection,
  });

  final String selection;
  final bool appliedImmediately;
  final bool appliesOnNextConnection;

  factory DeviceSpeedUpdate.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return DeviceSpeedUpdate(
      selection: reader.string('selection'),
      appliedImmediately: reader.boolean('applied_immediately'),
      appliesOnNextConnection: reader.boolean('applies_on_next_connection'),
    );
  }
}
