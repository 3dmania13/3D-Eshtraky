import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/mock/mock_data.dart';
import '../domain/recharge_transaction.dart';

abstract interface class RechargeRepository {
  Future<List<RechargeTransaction>> getRecharges();
}

class ApiRechargeRepository implements RechargeRepository {
  const ApiRechargeRepository(this._client);

  final ApiClient _client;

  @override
  Future<List<RechargeTransaction>> getRecharges() async {
    final rows = await _client.getJsonList(ApiEndpoints.recharges);
    return rows.map(RechargeTransaction.fromJson).toList(growable: false);
  }
}

class MockRechargeRepository implements RechargeRepository {
  const MockRechargeRepository();

  @override
  Future<List<RechargeTransaction>> getRecharges() async {
    await Future<void>.delayed(const Duration(milliseconds: 450));
    return MockData.recharges(DateTime.now());
  }
}
