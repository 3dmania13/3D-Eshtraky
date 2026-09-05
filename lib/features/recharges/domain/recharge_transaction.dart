import '../../../core/utils/json_reader.dart';

enum RechargeStatus { successful, pending, failed }

class RechargeTransaction {
  const RechargeTransaction({
    required this.id,
    required this.createdAt,
    required this.amount,
    required this.packageName,
    required this.addedBytes,
    required this.validityDays,
    required this.generatedExpiry,
    required this.referenceId,
    required this.status,
  });

  final String id;
  final DateTime createdAt;
  final double? amount;
  final String packageName;
  final int addedBytes;
  final int? validityDays;
  final DateTime? generatedExpiry;
  final String referenceId;
  final RechargeStatus status;

  factory RechargeTransaction.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return RechargeTransaction(
      id: reader.string('id'),
      createdAt: reader.dateTime('created_at'),
      amount: json['amount'] == null ? null : reader.decimal('amount'),
      packageName: reader.string('package_name'),
      addedBytes: reader.integer('added_bytes'),
      validityDays: json['validity_days'] == null
          ? null
          : reader.integer('validity_days'),
      generatedExpiry: json['generated_expiry'] == null
          ? null
          : reader.dateTime('generated_expiry'),
      referenceId: reader.string('reference_id'),
      status: RechargeStatus.values.byName(reader.string('status')),
    );
  }
}
