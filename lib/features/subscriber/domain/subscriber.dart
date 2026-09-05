import '../../../core/utils/byte_utils.dart';
import '../../../core/utils/date_utils.dart';
import '../../../core/utils/json_reader.dart';

enum SubscriberStatus { active, expired, disabled }

enum ConnectionStatus { connected, disconnected }

class Subscriber {
  const Subscriber({
    required this.id,
    required this.username,
    required this.status,
    required this.connectionStatus,
    required this.subscription,
    required this.activeDeviceCount,
  });

  final String id;
  final String username;
  final SubscriberStatus status;
  final ConnectionStatus connectionStatus;
  final Subscription subscription;
  final int activeDeviceCount;

  factory Subscriber.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    final subscriptionJson = json['subscription'];
    if (subscriptionJson is! Map<String, dynamic>) {
      throw const FormatException('Invalid subscription payload');
    }
    return Subscriber(
      id: reader.string('id'),
      username: reader.string('username'),
      status: SubscriberStatus.values.byName(reader.string('status')),
      connectionStatus: ConnectionStatus.values.byName(
        reader.string('connection_status'),
      ),
      subscription: Subscription.fromJson(subscriptionJson),
      activeDeviceCount: reader.integer('active_device_count'),
    );
  }
}

class Subscription {
  const Subscription({
    required this.id,
    required this.packageName,
    required this.totalBytes,
    required this.usedBytes,
    required this.startedAt,
    required this.expiresAt,
  });

  final String id;
  final String packageName;
  final int totalBytes;
  final int usedBytes;
  final DateTime startedAt;
  final DateTime expiresAt;

  int get remainingBytes =>
      ByteUtils.remainingBytes(total: totalBytes, used: usedBytes);
  double get usagePercentage =>
      ByteUtils.usagePercentage(total: totalBytes, used: usedBytes);
  bool isExpiredAt(DateTime now) => !expiresAt.isAfter(now);
  Duration remainingAt(DateTime now) =>
      AppDateUtils.remainingUntil(expiresAt, now);
  int remainingDaysAt(DateTime now) =>
      (remainingAt(now).inMinutes / Duration.minutesPerDay).ceil();

  factory Subscription.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return Subscription(
      id: reader.string('id'),
      packageName: reader.string('package_name'),
      totalBytes: reader.integer('total_bytes'),
      usedBytes: reader.integer('used_bytes'),
      startedAt: reader.dateTime('started_at'),
      expiresAt: reader.dateTime('expires_at'),
    );
  }
}
