import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/mock/mock_data.dart';
import '../domain/subscriber_device.dart';

abstract interface class DeviceRepository {
  Future<List<SubscriberDevice>> getDevices();
  Future<SubscriberDevice> renameDevice(String deviceId, String friendlyName);
  Future<DeviceSpeedUpdate> setDeviceSpeed(String deviceId, String selection);
  Future<void> disconnectDevice(String deviceId);
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

  @override
  Future<DeviceSpeedUpdate> setDeviceSpeed(
    String deviceId,
    String selection,
  ) async {
    final json = await _client.putJson(
      ApiEndpoints.deviceSpeed(deviceId),
      data: {'selection': selection},
    );
    return DeviceSpeedUpdate.fromJson(json);
  }

  @override
  Future<void> disconnectDevice(String deviceId) async {
    await _client.deleteJson(ApiEndpoints.device(deviceId));
  }
}

class MockDeviceRepository implements DeviceRepository {
  static const _namePrefix = 'device_friendly_name_';
  static const _speedPrefix = 'device_speed_selection_';

  @override
  Future<List<SubscriberDevice>> getDevices() async {
    await Future<void>.delayed(const Duration(milliseconds: 450));
    final preferences = await SharedPreferences.getInstance();
    return MockData.devices(DateTime.now()).map((device) {
      final localName = preferences.getString('$_namePrefix${device.id}');
      final selection = preferences.getString('$_speedPrefix${device.id}');
      return device.copyWith(
        friendlyName: localName,
        speedSelection: selection,
      );
    }).toList();
  }

  @override
  Future<DeviceSpeedUpdate> setDeviceSpeed(
    String deviceId,
    String selection,
  ) async {
    await Future<void>.delayed(const Duration(milliseconds: 300));
    final preferences = await SharedPreferences.getInstance();
    if (selection == 'default') {
      await preferences.remove('$_speedPrefix$deviceId');
    } else {
      await preferences.setString('$_speedPrefix$deviceId', selection);
    }
    return DeviceSpeedUpdate(
      selection: selection,
      appliedImmediately: true,
      appliesOnNextConnection: false,
    );
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

  @override
  Future<void> disconnectDevice(String deviceId) async {
    await Future<void>.delayed(const Duration(milliseconds: 300));
  }
}
