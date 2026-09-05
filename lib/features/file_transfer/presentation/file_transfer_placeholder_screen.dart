import 'package:flutter/material.dart';

import '../../../core/strings/app_strings.dart';
import '../../../core/widgets/main_scaffold.dart';

class FileTransferPlaceholderScreen extends StatelessWidget {
  const FileTransferPlaceholderScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.fileTransferShort)),
      body: SafeArea(
        child: PageFrame(
          child: Center(
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(28),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 82,
                      height: 82,
                      decoration: BoxDecoration(
                        color: Theme.of(context).colorScheme.primaryContainer,
                        borderRadius: BorderRadius.circular(26),
                      ),
                      child: Icon(
                        Icons.swap_horiz_rounded,
                        size: 44,
                        color: Theme.of(context).colorScheme.primary,
                      ),
                    ),
                    const SizedBox(height: 20),
                    Text(
                      AppStrings.fileTransfer,
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      AppStrings.fileTransferDescription,
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 18),
                    const Chip(
                      avatar: Icon(Icons.schedule_rounded, size: 18),
                      label: Text(AppStrings.comingSoon),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'ستتوفر كوحدة مستقلة باسم 3D Share.',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
