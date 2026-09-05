import '../../features/devices/domain/subscriber_device.dart';
import '../../features/notifications/domain/subscriber_notification.dart';
import '../../features/recharges/domain/recharge_transaction.dart';
import '../../features/subscriber/domain/subscriber.dart';
import '../../features/usage/domain/usage_models.dart';
import '../utils/byte_utils.dart';

abstract final class MockData {
  static DateTime _day(DateTime now, int daysAgo) =>
      DateTime(now.year, now.month, now.day).subtract(Duration(days: daysAgo));

  static Subscriber subscriber(DateTime now) => Subscriber(
    id: 'sub-demo-001',
    username: 'demo001',
    status: SubscriberStatus.active,
    connectionStatus: ConnectionStatus.connected,
    subscription: Subscription(
      id: 'subscription-001',
      packageName: 'باقة 30 جيجا',
      totalBytes: ByteUtils.gigabytesToBytes(30),
      usedBytes: ByteUtils.gigabytesToBytes(12.6),
      startedAt: _day(now, 24),
      expiresAt: now.add(const Duration(days: 6)),
    ),
    activeDeviceCount: 2,
  );

  static List<DailyUsage> dailyUsage(DateTime now) {
    const totals = [
      0.72,
      1.08,
      0.91,
      1.32,
      0.84,
      1.55,
      1.11,
      0.68,
      1.42,
      0.97,
      1.26,
      0.73,
    ];
    return List.generate(totals.length, (index) {
      final total = ByteUtils.gigabytesToBytes(totals[index]);
      final upload = (total * (0.12 + (index % 3) * 0.025)).round();
      return DailyUsage(
        date: _day(now, index),
        downloadBytes: total - upload,
        uploadBytes: upload,
      );
    });
  }

  static UsageSummary usageSummary(DateTime now) {
    final daily = dailyUsage(now);
    UsageBreakdown sum(Iterable<DailyUsage> items) => UsageBreakdown(
      downloadBytes: items.fold(0, (value, item) => value + item.downloadBytes),
      uploadBytes: items.fold(0, (value, item) => value + item.uploadBytes),
    );
    return UsageSummary(
      today: sum(daily.take(1)),
      yesterday: sum(daily.skip(1).take(1)),
      week: sum(daily.take(7)),
      month: sum(daily),
    );
  }

  static List<RadiusSession> sessions(DateTime now) => [
    RadiusSession(
      sessionId: 'sess-1005',
      startTime: now.subtract(const Duration(hours: 3, minutes: 18)),
      stopTime: null,
      duration: const Duration(hours: 3, minutes: 18),
      uploadBytes: ByteUtils.gigabytesToBytes(0.12),
      downloadBytes: ByteUtils.gigabytesToBytes(0.73),
      framedIp: '10.10.21.34',
      callingStationId: 'A4:C3:F0:21:8B:11',
      networkIdentifier: 'NAS-RYD-02',
      isActive: true,
    ),
    for (var index = 1; index < 5; index++)
      RadiusSession(
        sessionId: 'sess-100${5 - index}',
        startTime: now.subtract(Duration(days: index, hours: 5 + index)),
        stopTime: now.subtract(Duration(days: index, hours: 1)),
        duration: Duration(hours: 4 + index),
        uploadBytes: ByteUtils.gigabytesToBytes(0.08 * index),
        downloadBytes: ByteUtils.gigabytesToBytes(0.42 * index),
        framedIp: '10.10.21.${34 + index}',
        callingStationId: 'A4:C3:F0:21:8B:${11 + index}',
        networkIdentifier: index.isEven ? 'NAS-RYD-02' : 'NAS-RYD-01',
        isActive: false,
      ),
  ];

  static List<SubscriberDevice> devices(DateTime now) => [
    SubscriberDevice(
      id: 'device-phone',
      friendlyName: 'جوالي',
      macAddress: 'A4:C3:F0:21:8B:11',
      ipAddress: '10.10.21.34',
      connectionStartedAt: now.subtract(const Duration(hours: 3, minutes: 18)),
      sessionDuration: const Duration(hours: 3, minutes: 18),
      lastSeenAt: now,
      currentSessionBytes: ByteUtils.gigabytesToBytes(0.85),
      isOnline: true,
    ),
    SubscriberDevice(
      id: 'device-tv',
      friendlyName: 'التلفزيون',
      macAddress: '70:2C:1F:9D:44:02',
      ipAddress: '10.10.21.42',
      connectionStartedAt: now.subtract(const Duration(hours: 1, minutes: 42)),
      sessionDuration: const Duration(hours: 1, minutes: 42),
      lastSeenAt: now.subtract(const Duration(minutes: 1)),
      currentSessionBytes: ByteUtils.gigabytesToBytes(0.46),
      isOnline: true,
    ),
    SubscriberDevice(
      id: 'device-laptop',
      friendlyName: 'اللابتوب',
      macAddress: 'B8:27:EB:6A:19:7C',
      ipAddress: '10.10.21.19',
      connectionStartedAt: now.subtract(const Duration(days: 1, hours: 2)),
      sessionDuration: const Duration(hours: 2, minutes: 5),
      lastSeenAt: now.subtract(const Duration(days: 1)),
      currentSessionBytes: ByteUtils.gigabytesToBytes(0.62),
      isOnline: false,
    ),
    SubscriberDevice(
      id: 'device-mohammed',
      friendlyName: 'جوال محمد',
      macAddress: '1C:57:DC:82:4A:90',
      ipAddress: '10.10.21.51',
      connectionStartedAt: now.subtract(const Duration(days: 4, hours: 1)),
      sessionDuration: const Duration(minutes: 48),
      lastSeenAt: now.subtract(const Duration(days: 4)),
      currentSessionBytes: ByteUtils.gigabytesToBytes(0.18),
      isOnline: false,
    ),
  ];

  static List<RechargeTransaction> recharges(DateTime now) => List.generate(5, (
    index,
  ) {
    final date = now.subtract(Duration(days: 29 * index + 3));
    final packages = [
      'باقة 30 جيجا',
      'باقة 20 جيجا',
      'باقة 30 جيجا',
      'باقة 10 جيجا',
      'باقة 30 جيجا',
    ];
    final sizes = [30, 20, 30, 10, 30];
    return RechargeTransaction(
      id: 'recharge-${1005 - index}',
      createdAt: date,
      amount: sizes[index] * 1.5,
      packageName: packages[index],
      addedBytes: ByteUtils.gigabytesToBytes(sizes[index]),
      validityDays: 30,
      generatedExpiry: date.add(const Duration(days: 30)),
      referenceId:
          'TXN-${date.year}${date.month.toString().padLeft(2, '0')}-${1005 - index}',
      status: RechargeStatus.successful,
    );
  });

  static List<SubscriberNotification> notifications(DateTime now) => [
    SubscriberNotification(
      id: 'notification-1',
      type: NotificationType.expiringSoon,
      title: 'اشتراكك يقترب من الانتهاء',
      body: 'متبقي 6 أيام على انتهاء اشتراكك.',
      createdAt: now.subtract(const Duration(hours: 2)),
      isRead: false,
    ),
    SubscriberNotification(
      id: 'notification-2',
      type: NotificationType.newDevice,
      title: 'جهاز جديد',
      body: 'تم تسجيل جهاز جديد على اشتراكك: التلفزيون.',
      createdAt: now.subtract(const Duration(days: 1, hours: 3)),
      isRead: false,
    ),
    SubscriberNotification(
      id: 'notification-3',
      type: NotificationType.rechargeSuccessful,
      title: 'تم الشحن بنجاح',
      body: 'تم شحن 30 جيجا بنجاح.',
      createdAt: now.subtract(const Duration(days: 3)),
      isRead: true,
    ),
    SubscriberNotification(
      id: 'notification-4',
      type: NotificationType.usage75,
      title: 'تنبيه استهلاك',
      body: 'اقترب استهلاكك من 75% من الباقة.',
      createdAt: now.subtract(const Duration(days: 6)),
      isRead: true,
    ),
    SubscriberNotification(
      id: 'notification-5',
      type: NotificationType.systemMessage,
      title: 'تحسينات في الشبكة',
      body: 'تمت ترقية تجهيزات الشبكة المحلية في منطقتك.',
      createdAt: now.subtract(const Duration(days: 9)),
      isRead: true,
    ),
  ];
}
