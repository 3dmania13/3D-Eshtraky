import '../../../core/utils/json_reader.dart';

class UsageBreakdown {
  const UsageBreakdown({
    required this.downloadBytes,
    required this.uploadBytes,
  });

  final int downloadBytes;
  final int uploadBytes;
  int get totalBytes => downloadBytes + uploadBytes;

  factory UsageBreakdown.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return UsageBreakdown(
      downloadBytes: reader.integer('download_bytes'),
      uploadBytes: reader.integer('upload_bytes'),
    );
  }
}

class UsageSummary {
  const UsageSummary({
    required this.today,
    required this.yesterday,
    required this.week,
    required this.month,
  });

  final UsageBreakdown today;
  final UsageBreakdown yesterday;
  final UsageBreakdown week;
  final UsageBreakdown month;
}

class DailyUsage {
  const DailyUsage({
    required this.date,
    required this.downloadBytes,
    required this.uploadBytes,
  });

  final DateTime date;
  final int downloadBytes;
  final int uploadBytes;
  int get totalBytes => downloadBytes + uploadBytes;

  factory DailyUsage.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return DailyUsage(
      date: reader.dateTime('date'),
      downloadBytes: reader.integer('download_bytes'),
      uploadBytes: reader.integer('upload_bytes'),
    );
  }
}

class RadiusSession {
  const RadiusSession({
    required this.sessionId,
    required this.startTime,
    required this.stopTime,
    required this.duration,
    required this.uploadBytes,
    required this.downloadBytes,
    required this.framedIp,
    required this.callingStationId,
    required this.networkIdentifier,
    required this.isActive,
  });

  final String sessionId;
  final DateTime startTime;
  final DateTime? stopTime;
  final Duration duration;
  final int uploadBytes;
  final int downloadBytes;
  final String framedIp;
  final String callingStationId;
  final String networkIdentifier;
  final bool isActive;
  int get totalBytes => uploadBytes + downloadBytes;

  factory RadiusSession.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    final stop = reader.nullableString('stop_time');
    return RadiusSession(
      sessionId: reader.string('session_id'),
      startTime: reader.dateTime('start_time'),
      stopTime: stop == null ? null : DateTime.parse(stop),
      duration: Duration(seconds: reader.integer('duration_seconds')),
      uploadBytes: reader.integer('upload_bytes'),
      downloadBytes: reader.integer('download_bytes'),
      framedIp: reader.string('framed_ip'),
      callingStationId: reader.string('calling_station_id'),
      networkIdentifier: reader.string('network_identifier'),
      isActive: reader.boolean('is_active'),
    );
  }
}
