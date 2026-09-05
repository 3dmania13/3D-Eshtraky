import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/mock/mock_data.dart';
import '../domain/subscriber_device.dart';

abstract interface class DeviceRepository {
  Future<List<SubscriberDevice>> getDevices();
  Future<SubscriberDevice> renameDevice(String deviceId, String friendlyName);
}

class ApiDeviceRepository implements DeviceRepository {
  const ApiDeviceRepository(this._client);

  final ApiClient _client;

  @override
  Future<List<SubscriberDevice>> getDevices() async {
    final rows = await _client.getJsonList(ApiEndpoints.devices);
    return rows.map(SubscriberDevice.fromJson).toList(growable: false);
  }

  @override
  Future<SubscriberDevice> renameDevice(
    String deviceId,
    String friendlyName,
  ) async {
    final json = await _client.patchJson(
      ApiEndpoints.device(deviceId),
      data: {'friendly_name': friendlyName.trim()},
    );
    return SubscriberDevice.fromJson(json);
  }
}

class MockDeviceRepository implements DeviceRepository {
  static const _namePrefix = 'device_friendly_name_';

  @override
  Future<List<SubscriberDevice>> getDevices() async {
    await Future<void>.delayed(const Duration(milliseconds: 450));
    final preferences = await SharedPreferences.getInstance();
    return MockData.devices(DateTime.now()).map((device) {
      final localName = preferences.getString('$_namePrefix${device.id}');
      return localName == null
          ? device
          : device.copyWith(friendlyName: localName);
    }).toList();
  }

  @override
  Future<SubscriberDevice> renameDevice(
    String deviceId,
    String friendlyName,
  ) async {
    final name = friendlyName.trim();
    if (name.isEmpty) {
      throw const FormatException('Device name cannot be empty');
    }
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString('$_namePrefix$deviceId', name);
    final device = MockData.devices(
      DateTime.now(),
    ).firstWhere((item) => item.id == deviceId);
    return device.copyWith(friendlyName: name);
  }
}
