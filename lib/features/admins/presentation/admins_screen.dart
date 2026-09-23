import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/main_scaffold.dart';

class AdminsScreen extends StatefulWidget {
  const AdminsScreen({super.key});

  @override
  State<AdminsScreen> createState() => _AdminsScreenState();
}

class _AdminsScreenState extends State<AdminsScreen> {
  final _search = TextEditingController();
  final List<_Admin> _admins = [
    const _Admin(
      'mohammed',
      'mohammed@system.local',
      'M',
      true,
      'الآن',
      '192.168.230.111',
    ),
    const _Admin(
      'anwar',
      'anwar@system.local',
      'A',
      false,
      'منذ 3 ساعات',
      '192.168.230.144',
    ),
    const _Admin(
      'Hussain',
      'hussain@system.local',
      'H',
      false,
      'منذ 6 ساعات',
      '192.168.230.166',
    ),
  ];

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _addAdmin() {
    final name = TextEditingController();
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('إضافة مشرف جديد'),
        content: TextField(
          controller: name,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'اسم المشرف'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () {
              if (name.text.trim().isEmpty) {
                return;
              }
              setState(
                () => _admins.add(
                  _Admin(
                    name.text.trim(),
                    '${name.text.trim().toLowerCase()}@system.local',
                    name.text.trim()[0].toUpperCase(),
                    false,
                    'لم يسجّل الدخول',
                    '-',
                  ),
                ),
              );
              Navigator.pop(context);
            },
            child: const Text('إضافة'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final query = _search.text.trim().toLowerCase();
    final shown = _admins
        .where(
          (a) =>
              a.name.toLowerCase().contains(query) ||
              a.email.toLowerCase().contains(query),
        )
        .toList();
    return MainScaffold(
      title: 'إدارة المشرفين',
      currentIndex: 0,
      actions: [
        IconButton(
          onPressed: () {},
          tooltip: 'الإشعارات',
          icon: const Badge(
            label: Text('3'),
            child: Icon(Icons.notifications_none_rounded),
          ),
        ),
      ],
      body: PageFrame(
        child: ListView(
          children: [
            const _Breadcrumb(),
            const SizedBox(height: 14),
            _Hero(onAdd: _addAdmin),
            const SizedBox(height: 16),
            _Stats(admins: _admins),
            const SizedBox(height: 16),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  children: [
                    LayoutBuilder(
                      builder: (context, c) => c.maxWidth < 610
                          ? Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                _Search(
                                  controller: _search,
                                  onChanged: () => setState(() {}),
                                ),
                                const SizedBox(height: 10),
                                _Toolbar(onAdd: _addAdmin),
                              ],
                            )
                          : Row(
                              children: [
                                Expanded(
                                  child: _Search(
                                    controller: _search,
                                    onChanged: () => setState(() {}),
                                  ),
                                ),
                                const SizedBox(width: 12),
                                _Toolbar(onAdd: _addAdmin),
                              ],
                            ),
                    ),
                    const SizedBox(height: 14),
                    _AdminTable(
                      admins: shown,
                      onDelete: (a) => setState(() => _admins.remove(a)),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            const _FeatureCards(),
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }
}

class _Breadcrumb extends StatelessWidget {
  const _Breadcrumb();
  @override
  Widget build(BuildContext context) => const Row(
    children: [
      Text('الرئيسية'),
      Icon(Icons.chevron_left_rounded, size: 18),
      Text('الإدارة'),
      Icon(Icons.chevron_left_rounded, size: 18),
      Text('إدارة المشرفين', style: TextStyle(fontWeight: FontWeight.w800)),
    ],
  );
}

class _Hero extends StatelessWidget {
  const _Hero({required this.onAdd});
  final VoidCallback onAdd;
  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      constraints: const BoxConstraints(minHeight: 210),
      padding: const EdgeInsets.all(25),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: LinearGradient(
          colors: dark
              ? const [Color(0xFF082952), Color(0xFF06172C)]
              : const [Color(0xFFEAF4FF), Color(0xFFF9FCFF)],
        ),
        border: Border.all(color: AppTheme.primary.withValues(alpha: .18)),
      ),
      child: LayoutBuilder(
        builder: (context, c) {
          final compact = c.maxWidth < 720;
          final title = Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                'إدارة المشرفين',
                style: Theme.of(
                  context,
                ).textTheme.headlineMedium?.copyWith(fontSize: 30),
              ),
              const SizedBox(height: 8),
              Text(
                'أنشئ حسابات الإدارة، وحدد صلاحيات كل مشرف بدقة.',
                style: Theme.of(context).textTheme.bodyLarge,
              ),
              const SizedBox(height: 20),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: const [
                  _HeroChip(Icons.settings_rounded, 'تحكم كامل'),
                  _HeroChip(Icons.description_outlined, 'سجل الأنشطة'),
                  _HeroChip(Icons.admin_panel_settings_outlined, 'إدارة آمنة'),
                ],
              ),
            ],
          );
          final visual = const _PeopleVisual();
          return compact
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    title,
                    const SizedBox(height: 15),
                    Center(child: visual),
                  ],
                )
              : Row(
                  children: [
                    Expanded(child: title),
                    visual,
                  ],
                );
        },
      ),
    );
  }
}

class _HeroChip extends StatelessWidget {
  const _HeroChip(this.icon, this.label);
  final IconData icon;
  final String label;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 8),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.surface.withValues(alpha: .7),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, color: AppTheme.primary, size: 18),
        const SizedBox(width: 6),
        Text(
          label,
          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
        ),
      ],
    ),
  );
}

class _PeopleVisual extends StatelessWidget {
  const _PeopleVisual();
  @override
  Widget build(BuildContext context) => SizedBox(
    width: 260,
    height: 150,
    child: Stack(
      alignment: Alignment.center,
      children: [
        const Positioned(
          top: 4,
          child: Icon(
            Icons.workspace_premium_rounded,
            size: 53,
            color: Color(0xFFFFC439),
          ),
        ),
        const PositionedDirectional(
          start: 28,
          bottom: 11,
          child: _Person(size: 64, color: Color(0xFF1976F3)),
        ),
        const PositionedDirectional(
          end: 28,
          bottom: 11,
          child: _Person(size: 57, color: Color(0xFF7854EA)),
        ),
        const Positioned(
          bottom: 0,
          child: _Person(size: 85, color: Color(0xFF3285FF)),
        ),
      ],
    ),
  );
}

class _Person extends StatelessWidget {
  const _Person({required this.size, required this.color});
  final double size;
  final Color color;
  @override
  Widget build(BuildContext context) =>
      Icon(Icons.person_rounded, size: size, color: color);
}

class _Stats extends StatelessWidget {
  const _Stats({required this.admins});
  final List<_Admin> admins;
  @override
  Widget build(BuildContext context) {
    final items = [
      (
        Icons.groups_rounded,
        'إجمالي المشرفين',
        '${admins.length}',
        'مشرف في النظام',
        AppTheme.primary,
      ),
      (
        Icons.shield_rounded,
        'مديرو النظام',
        '${admins.length}',
        'لديهم صلاحية كاملة',
        const Color(0xFF7B43E8),
      ),
      (
        Icons.person_pin_circle_rounded,
        'المشرفون النشطون',
        '${admins.where((a) => a.active).length}',
        'متصل الآن',
        const Color(0xFF10AE72),
      ),
      (
        Icons.person_off_rounded,
        'المشرفون غير النشطين',
        '${admins.where((a) => !a.active).length}',
        'غير متصل حالياً',
        const Color(0xFFFF3C61),
      ),
    ];
    return LayoutBuilder(
      builder: (context, c) {
        final columns = c.maxWidth > 900
            ? 4
            : c.maxWidth > 550
            ? 2
            : 1;
        return GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: items.length,
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: columns,
            crossAxisSpacing: 14,
            mainAxisSpacing: 14,
            childAspectRatio: columns == 1 ? 3.6 : 2.6,
          ),
          itemBuilder: (context, i) {
            final x = items[i];
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    Container(
                      width: 49,
                      height: 49,
                      decoration: BoxDecoration(
                        color: x.$5.withValues(alpha: .13),
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: Icon(x.$1, color: x.$5),
                    ),
                    const SizedBox(width: 13),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Text(
                            x.$2,
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                          Text(
                            x.$3,
                            style: TextStyle(
                              fontSize: 25,
                              color: x.$5,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          Text(
                            x.$4,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }
}

class _Search extends StatelessWidget {
  const _Search({required this.controller, required this.onChanged});
  final TextEditingController controller;
  final VoidCallback onChanged;
  @override
  Widget build(BuildContext context) => TextField(
    controller: controller,
    onChanged: (_) => onChanged(),
    decoration: const InputDecoration(
      isDense: true,
      hintText: 'ابحث باسم المشرف أو البريد الإلكتروني...',
      prefixIcon: Icon(Icons.search_rounded),
    ),
  );
}

class _Toolbar extends StatelessWidget {
  const _Toolbar({required this.onAdd});
  final VoidCallback onAdd;
  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      OutlinedButton.icon(
        onPressed: () {},
        icon: const Icon(Icons.settings_rounded),
        label: const Text('إعدادات الصلاحيات'),
      ),
      const SizedBox(width: 8),
      FilledButton.icon(
        onPressed: onAdd,
        icon: const Icon(Icons.add_rounded),
        label: const Text('إضافة مشرف جديد'),
      ),
    ],
  );
}

class _AdminTable extends StatelessWidget {
  const _AdminTable({required this.admins, required this.onDelete});
  final List<_Admin> admins;
  final ValueChanged<_Admin> onDelete;
  @override
  Widget build(BuildContext context) {
    if (admins.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(32),
        child: Center(child: Text('لا توجد نتائج مطابقة.')),
      );
    }
    return LayoutBuilder(
      builder: (context, c) {
        if (c.maxWidth < 720) {
          return Column(
            children: admins
                .map((a) => _AdminMobile(a: a, onDelete: onDelete))
                .toList(),
          );
        }
        return SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: ConstrainedBox(
            constraints: BoxConstraints(minWidth: c.maxWidth),
            child: DataTable(
              headingTextStyle: const TextStyle(fontWeight: FontWeight.w900),
              columns: const [
                DataColumn(label: Text('#')),
                DataColumn(label: Text('المشرف')),
                DataColumn(label: Text('الدور')),
                DataColumn(label: Text('الحالة')),
                DataColumn(label: Text('آخر دخول')),
                DataColumn(label: Text('الإجراءات')),
              ],
              rows: List.generate(admins.length, (i) {
                final a = admins[i];
                return DataRow(
                  cells: [
                    DataCell(Text('${i + 1}')),
                    DataCell(_AdminIdentity(a)),
                    const DataCell(_RoleBadge()),
                    DataCell(_StateBadge(active: a.active)),
                    DataCell(_LastLogin(a)),
                    DataCell(
                      Row(
                        children: [
                          IconButton(
                            onPressed: () {},
                            icon: const Icon(
                              Icons.edit_outlined,
                              color: AppTheme.primary,
                            ),
                          ),
                          IconButton(
                            onPressed: () => onDelete(a),
                            icon: const Icon(
                              Icons.delete_outline_rounded,
                              color: Color(0xFFFF3C61),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                );
              }),
            ),
          ),
        );
      },
    );
  }
}

class _AdminMobile extends StatelessWidget {
  const _AdminMobile({required this.a, required this.onDelete});
  final _Admin a;
  final ValueChanged<_Admin> onDelete;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 8),
      leading: _Avatar(a),
      title: _AdminIdentityText(a),
      subtitle: Row(
        children: [
          _StateBadge(active: a.active),
          const SizedBox(width: 8),
          const _RoleBadge(),
        ],
      ),
      trailing: IconButton(
        onPressed: () => onDelete(a),
        icon: const Icon(
          Icons.delete_outline_rounded,
          color: Color(0xFFFF3C61),
        ),
      ),
    ),
  );
}

class _AdminIdentity extends StatelessWidget {
  const _AdminIdentity(this.a);
  final _Admin a;
  @override
  Widget build(BuildContext context) => Row(
    children: [_Avatar(a), const SizedBox(width: 9), _AdminIdentityText(a)],
  );
}

class _AdminIdentityText extends StatelessWidget {
  const _AdminIdentityText(this.a);
  final _Admin a;
  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    mainAxisAlignment: MainAxisAlignment.center,
    children: [
      Text(
        a.name,
        textDirection: TextDirection.ltr,
        style: const TextStyle(fontWeight: FontWeight.w900),
      ),
      Text(
        a.email,
        textDirection: TextDirection.ltr,
        style: Theme.of(context).textTheme.bodySmall,
      ),
    ],
  );
}

class _Avatar extends StatelessWidget {
  const _Avatar(this.a);
  final _Admin a;
  @override
  Widget build(BuildContext context) => CircleAvatar(
    backgroundColor: a.name == 'anwar'
        ? const Color(0xFF7546E8)
        : a.name == 'Hussain'
        ? const Color(0xFFFF4FA0)
        : AppTheme.primary,
    child: Text(
      a.letter,
      textDirection: TextDirection.ltr,
      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900),
    ),
  );
}

class _RoleBadge extends StatelessWidget {
  const _RoleBadge();
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
    decoration: BoxDecoration(
      color: const Color(0xFF7B43E8).withValues(alpha: .13),
      borderRadius: BorderRadius.circular(9),
    ),
    child: const Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(
          Icons.workspace_premium_rounded,
          size: 15,
          color: Color(0xFF7B43E8),
        ),
        SizedBox(width: 4),
        Text(
          'مدير كامل',
          style: TextStyle(
            color: Color(0xFF7B43E8),
            fontWeight: FontWeight.w800,
            fontSize: 12,
          ),
        ),
      ],
    ),
  );
}

class _StateBadge extends StatelessWidget {
  const _StateBadge({required this.active});
  final bool active;
  @override
  Widget build(BuildContext context) {
    final color = active ? const Color(0xFF10AE72) : const Color(0xFF7890B8);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .13),
        borderRadius: BorderRadius.circular(99),
      ),
      child: Text(
        active ? 'نشط' : 'غير نشط',
        style: TextStyle(
          color: color,
          fontWeight: FontWeight.w800,
          fontSize: 12,
        ),
      ),
    );
  }
}

class _LastLogin extends StatelessWidget {
  const _LastLogin(this.a);
  final _Admin a;
  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    mainAxisAlignment: MainAxisAlignment.center,
    children: [
      Text(a.lastLogin),
      Text(
        a.ip,
        textDirection: TextDirection.ltr,
        style: Theme.of(context).textTheme.bodySmall,
      ),
    ],
  );
}

class _FeatureCards extends StatelessWidget {
  const _FeatureCards();
  @override
  Widget build(BuildContext context) {
    const x = [
      (
        Icons.person_add_alt_1_rounded,
        'إضافة سريعة',
        'أضف مشرف جديد خلال ثوانٍ',
        Color(0xFF10A9E7),
      ),
      (
        Icons.settings_rounded,
        'صلاحيات مخصصة',
        'حدد صلاحيات دقيقة لكل مشرف',
        AppTheme.primary,
      ),
      (
        Icons.bar_chart_rounded,
        'مراقبة النشاط',
        'تابع عمليات الدخول والإجراءات',
        Color(0xFF7B43E8),
      ),
      (
        Icons.verified_user_outlined,
        'الأمان أولاً',
        'جميع العمليات مسجلة في سجل النشاطات',
        Color(0xFF8847E8),
      ),
    ];
    return LayoutBuilder(
      builder: (context, c) {
        final cols = c.maxWidth > 900 ? 4 : 2;
        return GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: x.length,
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: cols,
            crossAxisSpacing: 14,
            mainAxisSpacing: 14,
            childAspectRatio: cols == 2 ? 2.5 : 1.8,
          ),
          itemBuilder: (context, i) => Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: x[i].$4.withValues(alpha: .12),
                      borderRadius: BorderRadius.circular(13),
                    ),
                    child: Icon(x[i].$1, color: x[i].$4),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          x[i].$2,
                          style: const TextStyle(fontWeight: FontWeight.w900),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          x[i].$3,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _Admin {
  const _Admin(
    this.name,
    this.email,
    this.letter,
    this.active,
    this.lastLogin,
    this.ip,
  );
  final String name, email, letter, lastLogin, ip;
  final bool active;
}
