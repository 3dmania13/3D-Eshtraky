import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/errors/app_exception.dart';
import '../../../core/mock/mock_data.dart';
import '../domain/usage_models.dart';

class UsageData {
  const UsageData({
    required this.summary,
    required this.daily,
    required this.sessions,
  });

  final UsageSummary summary;
  final List<DailyUsage> daily;
  final List<RadiusSession> sessions;
}

abstract interface class UsageRepository {
  Future<UsageSummary> getSummary();
  Future<List<DailyUsage>> getDaily({DateTime? from, DateTime? to});
  Future<List<RadiusSession>> getSessions();
}

class ApiUsageRepository implements UsageRepository {
  const ApiUsageRepository(this._client);

  final ApiClient _client;

  @override
  Future<UsageSummary> getSummary() async {
    final json = await _client.getJson(ApiEndpoints.usageSummary);
    return UsageSummary(
      today: UsageBreakdown.fromJson(_object(json['today'])),
      yesterday: UsageBreakdown.fromJson(_object(json['yesterday'])),
      week: UsageBreakdown.fromJson(_object(json['week'])),
      month: UsageBreakdown.fromJson(_object(json['month'])),
    );
  }

  @override
  Future<List<DailyUsage>> getDaily({DateTime? from, DateTime? to}) async {
    final rows = await _client.getJsonList(
      ApiEndpoints.usageDaily,
      query: {
        if (from != null) 'from': from.toIso8601String().substring(0, 10),
        if (to != null) 'to': to.toIso8601String().substring(0, 10),
      },
    );
    return rows.map(DailyUsage.fromJson).toList(growable: false);
  }

  @override
  Future<List<RadiusSession>> getSessions() async {
    final rows = await _client.getJsonList(ApiEndpoints.sessions);
    return rows.map(RadiusSession.fromJson).toList(growable: false);
  }

  Map<String, dynamic> _object(Object? value) {
    if (value is Map<String, dynamic>) return value;
    throw const AppException('استجابة الاستهلاك غير صالحة.');
  }
}

class MockUsageRepository implements UsageRepository {
  const MockUsageRepository();

  @override
  Future<List<DailyUsage>> getDaily({DateTime? from, DateTime? to}) async {
    await Future<void>.delayed(const Duration(milliseconds: 550));
    final records = MockData.dailyUsage(DateTime.now());
    return records.where((item) {
      final afterFrom = from == null || !item.date.isBefore(from);
      final beforeTo = to == null || !item.date.isAfter(to);
      return afterFrom && beforeTo;
    }).toList();
  }

  @override
  Future<List<RadiusSession>> getSessions() async {
    await Future<void>.delayed(const Duration(milliseconds: 350));
    return MockData.sessions(DateTime.now());
  }

  @override
  Future<UsageSummary> getSummary() async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    return MockData.usageSummary(DateTime.now());
  }
}
