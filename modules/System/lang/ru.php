<?php
declare(strict_types=1);

return [
    'system.home.title' => 'LAAS CMS',
    'system.home.message' => 'Минимальный каркас запущен.',
    'backup.inspect.ok' => 'Резервная копия проверена',
    'backup.inspect.failed' => 'Проверка резервной копии не удалась',
    'backup.restore.confirm_1' => 'Введите RESTORE для продолжения',
    'backup.restore.confirm_2' => 'Введите имя файла резервной копии для подтверждения',
    'backup.restore.locked' => 'Восстановление уже выполняется',
    'backup.restore.forbidden_in_prod' => 'Восстановление отключено на продакшене (используйте --force)',
    'backup.restore.dry_run_ok' => 'Тестовый запуск завершен',
    'backup.restore.failed' => 'Восстановление не удалось',
    'backup.create.driver_mysqldump' => 'Драйвер БД: mysqldump',
    'backup.create.driver_pdo' => 'Драйвер БД: pdo',
    'cache.warmup.ok' => 'Предварительный прогрев шаблонов завершен',
    'cache.warmup.failed' => 'Предварительный прогрев шаблонов не удался',
    'cache.warmup.compiled' => 'Скомпилированных шаблонов: {count}',
];
