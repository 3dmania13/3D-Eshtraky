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
  static const recharges = '/api/v1/subscriber/recharges';
  static const notifications = '/api/v1/subscriber/notifications';
  static String notificationRead(String id) => '$notifications/$id/read';
}
