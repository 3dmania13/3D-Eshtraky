import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/mock/mock_data.dart';
import '../domain/subscriber.dart';

abstract interface class SubscriberRepository {
  Future<Subscriber> getCurrentSubscriber();
}

class ApiSubscriberRepository implements SubscriberRepository {
  const ApiSubscriberRepository(this._client);

  final ApiClient _client;

  @override
  Future<Subscriber> getCurrentSubscriber() async {
    return Subscriber.fromJson(await _client.getJson(ApiEndpoints.dashboard));
  }
}

class MockSubscriberRepository implements SubscriberRepository {
  const MockSubscriberRepository();

  @override
  Future<Subscriber> getCurrentSubscriber() async {
    await Future<void>.delayed(const Duration(milliseconds: 450));
    return MockData.subscriber(DateTime.now());
  }
}
