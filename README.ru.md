# SesamePortal

Языки: [English](README.md) | [Русский](README.ru.md)

SesamePortal - локальный PHP-портал видеонаблюдения для инсталляций
SesameDVR. Он управляет DVR-серверами, пользователями, группами, камерами,
персональными playback-токенами, избранным и предоставляет режимы просмотра
камер в списке, на карте и на сохранённых видеостенах через auth-backend для SesameDVR.

## Локальная разработка

```bash
php bin/portal migrate
php bin/portal create-admin admin admin123
php -S 127.0.0.1:8080 -t public
```

Откройте `http://127.0.0.1:8080`.

Локальное состояние по умолчанию хранится в `var/portal.sqlite`. Production
установка использует `/var/lib/sesame-portal`.

SQLite используется как backend хранения по умолчанию. PostgreSQL/MySQL можно
выбрать через config или переменные окружения:

```php
'db_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=sesame_portal',
'db_user' => 'sesame_portal',
'db_password' => 'secret',
```

Те же значения можно передать через `SESAME_PORTAL_DB_DSN`,
`SESAME_PORTAL_DB_USER` и `SESAME_PORTAL_DB_PASSWORD`.

Интерфейс поддерживает тот же набор языков, что и SesameDVR: `ru`, `en`, `de`,
`fr`, `es`, `it`, `pt`, `bg`, `pl`, `zh`, `ja`, `ko`, `ar` и `hy`. Язык по
умолчанию задаётся через `locale` или `SESAME_PORTAL_LOCALE`; пользователь может
сменить язык в выпадающем списке интерфейса или через `?lang=<code>`.

## Приложение на телефоне и сохранение входа

SesamePortal можно установить как PWA: приложение открывается отдельным окном
с иконки и запоминает адрес конкретного портала. Откройте портал по HTTPS,
нажмите «Установить приложение» на странице входа или в меню. На iPhone/iPad
используйте Safari → «Поделиться» → «На экран Домой». На Android установка
доступна также через меню браузера.

На телефоне над списком камер появляется предложение установки. «Позже»
скрывает его на 30 дней, при этом кнопка в меню остаётся доступной. В установленном
приложении предложение и кнопки установки скрыты. На Android кнопка открывает
системный диалог, если браузер предоставил эту возможность. Иначе открывается
пошаговая инструкция для текущей платформы; на iPhone она показывает действия
в Safari. В инструкции можно скопировать чистый адрес портала без параметров
плеера и токенов, чтобы открыть его из мессенджера в обычном браузере.

При первом входе отметьте «Запомнить меня» на личном устройстве. Сохранённый
вход не имеет фиксированного срока на сервере: он действует до выхода, смены
пароля, блокировки/удаления пользователя или отзыва доступа. Закрытие приложения,
перезапуск сервера и очистка краткоживущих PHP-сессий не требуют повторного входа.
Без этой отметки используется обычная сессия браузера.

Пароль в приложении не сохраняется. Для каждого входа с запоминанием создаётся
случайный токен устройства; в БД `remembered_logins` хранится только его хеш.
Cookie недоступна JavaScript (`HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS).
Выход отзывает токен текущего устройства; смена пароля и блокировка через UI/API
отзывают все сохранённые входы пользователя. Токены не входят в CLI-бэкап;
восстановление бэкапа удаляет существующие сохранённые входы.

У браузеров есть собственные ограничения хранения: Chrome ограничивает срок
cookie 400 днями. Portal автоматически продлевает её во время использования,
без ввода пароля. После очистки данных сайта, утраты данных приложения или
истечения срока хранения браузером потребуется войти снова. Это ограничение
браузера, а не принудительный срок авторизации Portal.
См. [ограничение Chrome](https://developer.chrome.com/blog/cookie-max-age-expires).

Настройка `remember_login_enabled` (по умолчанию `true`) включает сохранение
входа. За HTTPS reverse proxy укажите правильный `base_url` с `https://` либо
передавайте в PHP корректный параметр `HTTPS`. Заголовкам клиента
`X-Forwarded-Proto` портал автоматически не доверяет.

Service worker хранит только общедоступную страницу отсутствия связи.
Камеры, видео, API-ответы, токены воспроизведения и авторизованные страницы
не сохраняются в его кэше. Для просмотра видео нужен доступ к серверу.
Manifest и service worker доступны без входа: `/manifest.webmanifest`, `/sw.js`.
PWA рассчитана на размещение портала в корне HTTPS-сайта; localhost подходит
для разработки. Обычный HTTP на удалённом сервере не поддерживает PWA.

Проверки: `bash tests/http_smoke.sh`, `bash tests/release_smoke.sh` и
`node tests/pwa_browser.cjs` (требуются Playwright и установленный Chrome).
Последняя проверка использует временную БД и проверяет установку, повторный
запуск, отзыв доступа, продление cookie, мобильную вёрстку и работу без сети.

## Режимы камер

Каждая камера может работать в одном из трёх режимов:

- `managed`: SesamePortal записывает конфигурацию потока на выбранный
  SesameDVR-сервер через management API. Название потока
  (`display_name`/`displayName`) уходит в DVR как `displayName`, а
  `dvr_stream_name` остаётся URL-safe техническим именем потока для DVR
  endpoints.
- `edge_agent`: SesamePortal создаёт на DVR push-поток с
  `publisherKind=agent`, связывая его с `agent_id` и `agent_camera_id`.
- `read_only`: SesamePortal не меняет конфигурацию DVR и использует выбранный
  DVR-сервер вместе с `dvr_stream_name` только для preview, auth и playback.

При открытии настроек существующей камеры портал читает актуальную конфигурацию
потока с DVR. Сохранение отправляет только поля, изменённые относительно снимка
этой формы; неизменённые параметры, в том числе правки на DVR после открытия
формы, обратно не отправляются. У каждой вкладки свой снимок. Если прочитать DVR
не удалось, сохранение управляемой камеры блокируется, а не записывает кешированную
конфигурацию. Обновление через API передаёт только явно указанные поля.
Создание камеры и отдельная команда ручной синхронизации по-прежнему передают
полную конфигурацию, управляемую порталом.

Общая таблица камер обновляет глубину и признак записи архива одним запросом
списка потоков к каждому выбранному DVR до фильтрации и разбиения на страницы.
Обновляется только локальная копия данных для отображения, не конфигурация DVR.
Эта копия хранится отдельно от настроек редактирования и не затирает ещё не
отправленные правки `skipSync`.
Открытие формы камеры также обновляет эти данные. При недоступном DVR или
отсутствующем потоке сохраняются последние известные значения и выводится
предупреждение в таблице.

Для камеры можно включить HTML/CSS watermark с login пользователя поверх
Portal player и задать его интенсивность. Поток при этом не транскодируется.

## Интерфейс просмотра

Раздел **Просмотр → Список** (ранее «Мозаика», адрес `/` не изменён) поддерживает фильтрацию по всем камерам, избранному и группам,
регистронезависимый поиск по названию камеры или техническому имени потока,
пагинацию и переключатель плотности от 2 до 6 камер в ряду. Размер страницы
зависит от плотности: 4/6/12/15/18 камер для 2/3/4/5/6 колонок.
Карточки камер имеют фиксированное соотношение сторон 16:9. Preview
предзагружаются за loader и подменяются только после полной загрузки, поэтому
при обновлении не видно промежуточной перерисовки изображения. Частота
обновления preview выбирается в **Личные настройки → Список**, включая режим
`Отключено`. Если поток на DVR сейчас недоступен, карточка показывает статус
`Поток недоступен`; при этом старое preview может оставаться видимым с этим
статусом поверх изображения.

Карта поддерживает те же фильтры и поиск. Она автоматически подбирает масштаб
под текущий набор камер, объединяет близкие камеры в кластеры
на малом масштабе, показывает направление и сектор обзора камеры, а также даёт
ту же кнопку добавления в избранное, что и список.

**Просмотр → Видеостены** (`/video-walls`) содержит персональные сохранённые
наборы камер. Администратор видит видеостены всех пользователей; остальные
пользователи видят только свои. Редактор поддерживает дерево групп, поиск,
перестановку камер перетаскиванием или кнопками и сетку от 1×1 до 6×6.
Количество выбранных камер не может превышать количество ячеек.
Видеостена запускает отдельные embed players DVR, без транскодирования
на Portal. Общий timeline в стиле embed-плеера объединяет интервалы всех камер
по логическому «ИЛИ»: отдельно события и отдельно записи. Есть переход по времени,
пауза/воспроизведение, скорость 0.5×–8×, LIVE и полноэкранный просмотр.
Разрывы записи и буферизация одной камеры не сдвигают остальные. Подписи наложены
поверх видео, промежутков между ячейками нет. В полном экране панель управления
скрывается при бездействии и появляется при движении мыши или касании.
Нужен обновлённый DVR с протоколом управления видеостеной v1; при скрытом архиве
остаются только live-контролы. [Контракт и ограничения](docs/VIDEO-WALL-PLAYBACK.md).
Плееры вне
видимой области и в скрытой вкладке останавливаются; при возобновлении берётся
актуальный токен, заново проверяются права и применяется общее время воспроизведения. Водяной знак с логином накладывается
на область видео, не на подпись камеры. Наследование групп не изменено.
Административный раздел **Камеры** сохраняет прежнее название и назначение.

Все даты и время в интерфейсе отображаются в часовом поясе браузера.
Сервер хранит и передаёт время как абсолютные значения.

## Скриншоты

### SesameDVR

| Потоки и player | Мониторинг | ONVIF |
| --- | --- | --- |
| <img src="docs/screenshots/sesamedvr-streams.jpg" alt="SesameDVR: потоки и player" width="280"> | <img src="docs/screenshots/sesamedvr-monitoring.jpg" alt="SesameDVR: мониторинг" width="280"> | <img src="docs/screenshots/sesamedvr-onvif.jpg" alt="SesameDVR: ONVIF" width="280"> |

### SesamePortal: desktop

| Dashboard | Мозаика | Карта | Player |
| --- | --- | --- | --- |
| <img src="docs/screenshots/sesameportal-desktop-dashboard.jpg" alt="SesamePortal desktop: dashboard" width="220"> | <img src="docs/screenshots/sesameportal-desktop-mosaic.jpg" alt="SesamePortal desktop: мозаика" width="220"> | <img src="docs/screenshots/sesameportal-desktop-map.jpg" alt="SesamePortal desktop: карта" width="220"> | <img src="docs/screenshots/sesameportal-desktop-player.jpg" alt="SesamePortal desktop: player" width="220"> |

### SesamePortal: mobile

| Мозаика | Карта | Player |
| --- | --- | --- |
| <img src="docs/screenshots/sesameportal-mobile-mosaic.jpg" alt="SesamePortal mobile: мозаика" width="180"> | <img src="docs/screenshots/sesameportal-mobile-map.jpg" alt="SesamePortal mobile: карта" width="180"> | <img src="docs/screenshots/sesameportal-mobile-player.jpg" alt="SesamePortal mobile: player" width="180"> |

## Production-установка

```bash
sudo bash scripts/install.sh \
  --domain portal.example.com \
  --email admin@example.com \
  --admin-login admin \
  --admin-password 'change-me-now'
```

Инсталлятор создаёт nginx site, подключает обнаруженный php-fpm socket,
инициализирует SQLite, создаёт первого администратора и может выпустить
сертификат Let's Encrypt через `certbot certonly --webroot`. Он записывает
только site-файл SesamePortal и не позволяет certbot переписывать существующие
nginx site-конфиги. Также installer устанавливает
`/usr/local/sbin/sesame-portal-update` и sudoers-правило, чтобы администратор
мог обновлять Portal из web UI в разделе `Настройки`.

Для repair/update запуска поверх существующей базы:

```bash
sudo bash scripts/install.sh \
  --domain portal.example.com \
  --email admin@example.com \
  --repair
```

Инсталлятор делает backup текущего release, nginx site, cron entry, config и
SQLite-файлов перед применением изменений. Если шаг установки завершается
ошибкой, он восстанавливает эти файлы и по возможности reload'ит nginx/php-fpm.

## Первый вход

После установки откройте URL портала:

```text
https://portal.example.com
```

Используйте логин и пароль, которые были переданы в install script:

- логин: значение `--admin-login`, по умолчанию `admin`;
- пароль: значение `--admin-password`.

Для примера из команды выше:

```text
login: admin
password: change-me-now
```

При запуске с `--repair` инсталлятор не меняет существующих администраторов,
если `--admin-password` не был передан.

## Установка SesameDVR Trial

SesamePortal обычно подключается к одному или нескольким серверам SesameDVR.
Для GitHub evaluation-инсталляций используйте этот публичный trial-ключ
SesameDVR:

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh | sudo bash -s -- --license-key SDVR-TRIAL-85GT2-A7YYD-HSSEN-YW98U
```

Для публичного HTTPS-доступа передайте домен DVR и ACME email:

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh \
  | sudo bash -s -- \
      --license-key SDVR-TRIAL-85GT2-A7YYD-HSSEN-YW98U \
      --publish-service \
      --publish-server-name dvr.example.com \
      --publish-acme \
      --acme-email admin@example.com
```

Подробнее:
[`docs/SESAME-DVR-TRIAL-INSTALL.ru.md`](docs/SESAME-DVR-TRIAL-INSTALL.ru.md).

Дополнительная документация SesameDVR:

- [Описание возможностей и продукта SesameDVR](docs/SESAME-DVR-PRODUCT-DESCRIPTION.ru.md)
- [Руководство пользователя SesameDVR](docs/sesame-dvr-user-guide.ru.md)
- [HTTP API SesameDVR](docs/sesame-dvr-api.ru.md)
- [Настройка DVR-хранилища SesameDVR](docs/dvr-storage-configuration.ru.md)
- [Prometheus / OpenMetrics endpoint SesameDVR](docs/prometheus-metrics-endpoint.ru.md)
- [BEAM hot patch manifest SesameDVR](docs/beam-hot-patch-manifest.ru.md)
- [Runbook DVR-side failover SesameDVR](docs/dvr-cluster-failover-runbook.ru.md)

## CLI

```bash
php bin/portal migrate
php bin/portal create-admin <login> <password>
php bin/portal rotate-tokens
php bin/portal rotate-secrets
php bin/portal backup /path/to/backup.json
php bin/portal restore /path/to/backup.json
```

`rotate-secrets` заново шифрует сохранённые SesameDVR management tokens с
использованием текущего `crypto_primary_key`, сохраняя доступ к старым key id
на период ротации.

## Структура кода

`app/Portal.php` остаётся общей точкой подключения для веб-приложения, CLI и
тестов. Классы вынесены в отдельные файлы; `App` объединяет модули API, страниц,
Auth Backend, UI и общих вспомогательных методов. Границы модулей и требования
к обновлению описаны в [архитектуре PHP-модулей](docs/ARCHITECTURE.md).

## Проверки

```bash
find app -name '*.php' -exec php -l {} \;
php tests/module_bootstrap.php
php tests/camera_settings.php
bash -n scripts/install.sh
tests/http_smoke.sh
bash tests/release_smoke.sh
```
