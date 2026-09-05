export class AppError extends Error {
  constructor(
    public readonly statusCode: number,
    public readonly code: string,
    message: string,
    public readonly details?: Readonly<Record<string, unknown>>,
  ) {
    super(message);
    this.name = 'AppError';
  }
}

export const invalidCredentials = () =>
  new AppError(401, 'INVALID_CREDENTIALS', 'اسم المستخدم أو كلمة المرور غير صحيحة.');

export function toPublicError(error: unknown): AppError {
  if (error instanceof AppError) return error;
  return new AppError(500, 'INTERNAL_ERROR', 'حدث خطأ غير متوقع في الخادم.');
}
