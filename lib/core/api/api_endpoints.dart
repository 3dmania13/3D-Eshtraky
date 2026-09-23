abstract final class ApiEndpoints {
  static const login = '/api/v1/auth/login';
  static const codeLogin = '/api/v1/auth/code-login';
  static const refresh = '/api/v1/auth/refresh';
  static const logout = '/api/v1/auth/logout';
  static const profile = '/api/v1/subscriber/profile';
  static const dashboard = '/api/v1/subscriber/dashboard';
  static const usageSummary = '/api/v1/subscriber/usage/summary';
  static const usageDaily = '/api/v1/subscriber/usage/daily';
  static const sessions = '/api/v1/subscriber/sessions';
  static const devices = '/api/v1/subscriber/devices';
  static String device(String id) => '$devices/$id';
  static String deviceSpeed(String id) => '${device(id)}/speed';
  static const recharges = '/api/v1/subscriber/recharges';
  static const notifications = '/api/v1/subscriber/notifications';
  static const notificationsReadAll = '$notifications/read-all';
  static const pushToken = '$notifications/push-token';
  static const speed = '/api/v1/subscriber/speed';
  static const connectionLimit = '/api/v1/subscriber/connection-limit';
  static const feedback = '/api/v1/subscriber/feedback';
  static String notificationRead(String id) => '$notifications/$id/read';
}
