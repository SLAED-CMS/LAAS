<?php
declare(strict_types=1);

return [
    'users.login.title' => 'Вход',
    'users.login.username' => 'Имя пользователя',
    'users.login.password' => 'Пароль',
    'users.login.submit' => 'Войти',
    'users.login.invalid' => 'Неверные учетные данные.',
    'users.login.forgot_password' => 'Забыли пароль?',
    'users.logout' => 'Выход',

    'users.password_reset.request_title' => 'Сброс пароля',
    'users.password_reset.email' => 'Email адрес',
    'users.password_reset.submit' => 'Отправить ссылку для сброса',
    'users.password_reset.back_to_login' => 'Вернуться к входу',
    'users.password_reset.request_success' => 'Если учетная запись с таким email существует, на нее была отправлена ссылка для сброса пароля.',
    'users.password_reset.rate_limit_exceeded' => 'Слишком много запросов на сброс пароля. Пожалуйста, попробуйте позже.',
    'users.password_reset.email_subject' => 'Запрос на сброс пароля',

    'users.password_reset.form_title' => 'Установка нового пароля',
    'users.password_reset.new_password' => 'Новый пароль',
    'users.password_reset.confirm_password' => 'Подтвердите пароль',
    'users.password_reset.reset_submit' => 'Сбросить пароль',
    'users.password_reset.passwords_do_not_match' => 'Пароли не совпадают.',

    'users.password_reset.success_title' => 'Пароль успешно сброшен',
    'users.password_reset.success_message' => 'Ваш пароль был успешно сброшен. Теперь вы можете войти с новым паролем.',
    'users.password_reset.success' => 'Ваш пароль был успешно сброшен.',
    'users.password_reset.go_to_login' => 'Перейти ко входу',

    'users.password_reset.invalid_title' => 'Неверная или истекшая ссылка для сброса',
    'users.password_reset.invalid_message' => 'Эта ссылка для сброса пароля недействительна или истекла.',
    'users.password_reset.invalid_instructions' => 'Ссылки для сброса пароля действительны в течение 1 часа. При необходимости запросите новую.',
    'users.password_reset.request_new' => 'Запросить новую ссылку для сброса',

    'users.2fa.setup_title' => 'Двухфакторная аутентификация',
    'users.2fa.currently_enabled' => 'Двухфакторная аутентификация в настоящее время включена для вашей учетной записи.',
    'users.2fa.disable_instructions' => 'Чтобы отключить 2FA, пожалуйста, подтвердите свой пароль.',
    'users.2fa.password_confirm' => 'Пароль',
    'users.2fa.disable_button' => 'Отключить 2FA',
    'users.2fa.regenerate_codes' => 'Генерировать новые резервные коды',
    'users.2fa.enable_description' => 'Двухфакторная аутентификация добавляет дополнительный уровень безопасности к вашей учетной записи, требуя код из приложения-аутентификатора в дополнение к паролю.',
    'users.2fa.enable_button' => 'Включить 2FA',

    'users.2fa.enable_title' => 'Включение двухфакторной аутентификации',
    'users.2fa.scan_qr_instructions' => 'Отсканируйте этот QR-код с помощью вашего приложения-аутентификатора (Google Authenticator, Authy и т.д.):',
    'users.2fa.manual_entry' => 'Или введите этот секретный ключ вручную:',
    'users.2fa.verification_code' => 'Код подтверждения',
    'users.2fa.verify_button' => 'Проверить и включить',
    'users.2fa.cancel' => 'Отмена',

    'users.2fa.backup_codes_title' => 'Резервные коды',
    'users.2fa.enabled_success' => 'Двухфакторная аутентификация была успешно включена!',
    'users.2fa.backup_codes_regenerated' => 'Резервные коды были сгенерированы заново.',
    'users.2fa.backup_codes_warning' => 'Сохраните эти резервные коды в безопасном месте. Каждый код можно использовать один раз для доступа к вашей учетной записи, если вы потеряете доступ к приложению-аутентификатору.',
    'users.2fa.continue' => 'Продолжить',

    'users.2fa.verify_title' => 'Двухфакторная аутентификация',
    'users.2fa.verify_instructions' => 'Введите код подтверждения из вашего приложения-аутентификатора или используйте резервный код.',
    'users.2fa.use_backup_code' => 'Вы также можете использовать резервный код, если потеряли доступ к приложению-аутентификатору.',
    'users.2fa.verify_submit' => 'Проверить',
    'users.2fa.cancel_login' => 'Отмена',

    'users.2fa.invalid_code' => 'Неверный код подтверждения. Пожалуйста, попробуйте снова.',
    'users.2fa.invalid_password' => 'Неверный пароль.',
    'users.2fa.setup_expired' => 'Сеанс настройки истек. Пожалуйста, начните процесс настройки заново.',
    'users.2fa.already_enabled' => 'Двухфакторная аутентификация уже включена.',
    'users.2fa.not_enabled' => 'Двухфакторная аутентификация не включена.',
    'users.2fa.disabled_success' => 'Двухфакторная аутентификация была успешно отключена.',
];
