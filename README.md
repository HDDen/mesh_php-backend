Небольшой php-сервер для приёма апдейтов от telegram через вебхук (получение сообщений из группы через добавленного в неё бота, либо локально развернутый tg-клиент Telethon, позволяюего работать через учетную запись пользователя напрямую), и их передачи по polling-запросу.

Принятые из telegram сообщения сохраняются в файле `./data/messages.json.php`, а сообщения, предназначенные для отправки обратно в telegram, либо отправляются сразу (если работаем через бота), либо, если включен режим передачи обратно по polling-запросу, сохраняются в `./data/externalpoll_to_tg.json.php`.

Требуется PHP > 7.0. Сервер можно развернуть локально, используя любой привычный вебсервер - например, [Laragon 6 (laragon-wamp.exe)](https://github.com/leokhoa/laragon/releases/tag/6.0.0) (либо новее, если есть активная лицензия), или [OSPanel](https://ospanel.io/download/).

Для локального использования рекомендуется использовать py-порт сервера, [HDDen/mesh_py-local-backend](https://github.com/HDDen/mesh_py-local-backend). Это позволит избежать доп. зависимости в виде локального php-сервера.

## Quick start

Скачать+залить или клонировать из консоли проект на сервер. Переименовать пример конфига в config.php.

```
git clone https://github.com/HDDen/mesh_php-backend.git
cd ./mesh_php-backend/php_backend
mv config---example.php config.php
nano config.php
```

### Настраиваем конфиг

Необходимо установить следующие константы, они будут использоваться как токены для доступа к функционалу сервера:

`EXTERNAL_ACCESS_TOKEN`, `ADMIN_TOKEN` (по-умолчанию равен `EXTERNAL_ACCESS_TOKEN`), `TG_SUBSCRIBE_TOKEN`

В качестве значений указываем случайную буквенно-числовую строку на латинице произвольной длины.

#### Если работаем через бота:

В этом режиме необходимо разворачивать бэкенд в облаке, либо пробрасывать домен извне на ПК.
Если отправляем и получаем сообщения через telegram-бота, в константе `BOT_TOKEN` указываем токен от бота, добавленного в нужную группу.

Устанавливаем вебхук для получения обновлений от Telegram - понадобится вышеупомянутые токены и url к основному файлу сервера `bot.php`, выполняем запрос через curl:

`curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" -d "url=https://example.ru/mesh_php-backend/php_backend/bot.php?token=<TG_SUBSCRIBE_TOKEN>"`

Tip: замечено, что вебхук может время от времени слетать. Для этого предусмотрен механизм автообновления подписки раз в `WEBHOOK_INTERVAL` секунд, проверка таймаута производится каждом входящем обращении (отключается в константе `REFRESH_WEBHOOK_ON_EACH_REQUEST`) . Если работаем через учётную запись пользователя напрямую, и отправляем сообщения обратно в telegram по poling-запросу, рекомендуется установить опцию в `false`.

Также можно создать задание в cron для обновления подписки каждые n минут, (понадобится установка `ADMIN_TOKEN` в конфиге в виде случайной строки):

```
curl -X POST -H "Content-Type: application/json" -d '{"token": ADMIN_TOKEN}' https://example.ru/mesh_php-backend/php_backend/bot.php?action=set_webhook
```

#### Если не пользуемся ботом, а работаем с Telegram через, например, локально запущенный скрипт для Telethon:

Переключаем в конфиге константу `SEND_TO_TG_THROUGH_EXTERNAL_POLL` в `true`. `BOT_TOKEN` в этом случае не нужен, но всё еще нужно указывать `TG_SUBSCRIBE_TOKEN`. Сообщения, полученные извне и предназначающиеся для отправки в telegram, начнут сохраняться в файле `SEND_TO_TG_THROUGH_EXTERNAL_POLL_MESSAGES_FILE` (по-умолчанию `./data/externalpoll_to_tg.json.php`).

Также устанавливаем `REFRESH_WEBHOOK_ON_EACH_REQUEST` в `false`.

Теперь для получения сообщений пользовательским скриптом, и дальнейшей их трансляцией в telegram, нужно будет направить POST-запрос с телом `{"token": EXTERNAL_ACCESS_TOKEN}` на url `https://example.ru/mesh_php-backend/php_backend/bot.php?action=extpoll_get_messages`, в ответ получим JSON:

```
{
   "messages":[
      {
         "date":"18.01 19:44",
         "msg":"Foo",
         "chat_id":"-10055555555"
      },
      {
         date":"18.01 19:44",
         "msg":"Bar",
         "chat_id":"-10055555555"
      }
   ]
}
```

#### Ограничение доступа по IP:

Доступ к административным функциям (`get_messages`, `send_message`, `set_webhook`, `mark_all_delivered`, `delete_messages`, `extpoll_get_messages`, `extpoll_delete_messages`) можно ограничить массивом разрешённых IP, диапазоны не поддерживаются. Указывается в виде массива в константе `ALLOWED_IP`:

```
define("ALLOWED_IP", [
    '255.255.255.255',
    '1.2.3.4',
    '192.168.0.1',
]);
```

## Обращение к методам сервера:

В большинстве случаев обращения к серверу выполняются в виде POST-запроса с json, внутри которого передаётся token:

```
https://example.ru/mesh_php-backend/php_backend/bot.php?action=get_messages
```

```
{
    "token": ...,
    "chat_id": "-1005654654654"
}
```

Список возможных операций можно посмотреть, перейдя по url обработчика:

```
https://example.ru/mesh_php-backend/php_backend/bot.php
```
