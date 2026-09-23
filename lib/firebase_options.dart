import 'package:firebase_core/firebase_core.dart';

/// Firebase settings for the registered Android application.
/// These identifiers are client configuration, not service-account credentials.
abstract final class DefaultFirebaseOptions {
  static const android = FirebaseOptions(
    apiKey: 'AIzaSyAa3Ic2v5QCk-h4lP3OmUEzaPXaIlBSbn0',
    appId: '1:529754196513:android:33cdded449b269a0fe7160',
    messagingSenderId: '529754196513',
    projectId: 'eshtraky-e6a38',
    storageBucket: 'eshtraky-e6a38.firebasestorage.app',
  );
}
