# MEDIA Информ Плюс — сайт-визитка

Одностраничный сайт ТОО «MEDIA Информ Плюс» (Костанай, Рудный): три направления — **оценка**, **перепланировка**,
**риелтор**, у каждого свой специалист и свои контакты. Кнопка «Связаться» открывает окно записи на консультацию
(как на data-pro.kz). Тексты об оценке — дословно из брифа клиента (`content/brief.md`).

Сайт: **https://mip-site.imakanovr.workers.dev** (Cloudflare Worker `mip-site`). Домен клиента — `mip.com.kz`
(куплен на hoster.kz). Подключение: добавить домен в Cloudflare (Domains → Add a domain, тариф Free), в панели
hoster.kz заменить NS-серверы на выданные Cloudflare (перед этим проверить, что Cloudflare подтянул MX/TXT почты),
затем Workers → mip-site → Settings → Domains & Routes → Add → Custom domain.

## Что где

| Файл | Что внутри |
|---|---|
| `public/index.html` | Вся страница: первый экран, направления, о компании, оценка (что оцениваем, 12 целей, заказчик, документы, 7 шагов), перепланировка, риелтор, вопросы, запись, партнёры, контакты |
| `public/site.config.js` | **Контакты компании и трёх специалистов** — имя, должность, фото, телефон, WhatsApp, Telegram, Instagram, e-mail, адрес; **партнёры** для блока «Наши партнёры» |
| `public/app.js` | Окно «Связаться», форма записи, маска телефона, меню, вкладки документов, анимации |
| `public/styles.css` | Стили, одна светлая тема, фирменный синий `#27449E` с логотипа |
| `public/img/` | Логотип в SVG (полный, белый, «МИП», знак), фото в `img/photo/`, логотипы партнёров в `img/partners/` |
| `worker.js` | Отдаёт файлы и принимает заявки `POST /api/lead` → Telegram |
| `tools/cf-deploy.mjs` | Загрузка файлов на Cloudflare без wrangler |

## Как заполнить контакты специалистов

Открыть `public/site.config.js` и вписать данные в `specialists.valuation` / `replan` / `deals`. Пустые поля на сайте
не показываются; пока у специалиста нет своего телефона и почты, везде стоят общие контакты компании.
Фото специалиста — квадратный файл в `public/img/team/` и путь `team/имя.jpg` в поле `photo`. Поле `address` — где
принимает специалист (у всех трёх — Рудный, ул. Ленина, 135; у перепланировки с кабинетом). `avatarIcon: true` — в кружке значок направления вместо
инициалов; нужен, когда вместо человека указана организация. Специалист по перепланировке — ТОО «Успех Проект»: он же
в блоке партнёров, общие данные лежат в константе `USPEKH` вверху `site.config.js`.

Общий номер компании (`company.phone`) стоит в шапке на любой ширине экрана; на телефонах он занимает место кнопки
«Связаться» — запись остаётся в первом экране, в нижней плавающей панели и в меню.

## Партнёры

Блок «Наши партнёры» (перед контактами) собирается из списка `partners` в `public/site.config.js`: название, подпись,
логотип, услуги, адрес, телефоны, почта. Новый партнёр — ещё один объект в списке; логотип — файл в
`public/img/partners/` (до 640 px по ширине). Пустой список — блок и ссылки на него в меню и подвале скрываются.

Сейчас там ТОО «Успех Проект» (архитектор, перепланировка). Логотип `img/partners/uspekh-proekt.jpg` — временный,
прислан клиентом: на картинке видны водяные знаки фотостока; клиент пришлёт новый. Новый логотип класть **под новым
именем файла** и менять путь в `logo`: картинки из `/img/` браузер кеширует на 30 дней (`_headers`).

## Куда приходят заявки

- **Сейчас:** после отправки формы посетитель видит кнопки «Отправить в WhatsApp» (если у специалиста указан WhatsApp),
  «Написать в Telegram», «Отправить на почту» и «Позвонить» — текст заявки уже составлен.
- **Автоматически в Telegram:** создать бота у @BotFather, добавить его в чат (или написать ему), узнать chat_id и задать
  в Cloudflare → Workers → mip-site → Settings → Variables and Secrets: `TG_TOKEN` (секрет) и `TG_CHAT`.
  Своим чатом для каждого специалиста — `TG_CHAT_VALUATION`, `TG_CHAT_REPLAN`, `TG_CHAT_DEALS`.
  После этого заявки уходят сами, а посетитель видит «Заявка отправлена».

## Выкладка (Cloudflare Workers)

1. `node tools/cf-deploy.mjs manifest public` → POST `/accounts/{id}/workers/scripts/mip-site/assets-upload-session`
   с телом `{ "manifest": … }`.
2. `node tools/cf-deploy.mjs upload public <jwt-файл> '<buckets>'` — загружает только изменившиеся файлы,
   печатает completion jwt.
3. PUT `/accounts/{id}/workers/scripts/mip-site` (multipart: metadata + `worker.js`), metadata:
   `main_module: "worker.js"`, `compatibility_date: "2026-09-01"`, `bindings: [{ type: "assets", name: "ASSETS" }]`,
   `assets: { jwt, config: { html_handling: "none", not_found_handling: "404-page", _headers: <public/_headers> } }`,
   `compatibility_flags: ["assets_navigation_has_no_effect"]` — без него браузер на «/» получит 404.html,
   `keep_bindings: ["secret_text", "plain_text"]` — чтобы не стереть TG_TOKEN и TG_CHAT.
   Только код воркера без файлов — то же самое с `keep_assets: true` вместо `assets`.
   Если в облачной среде Claude сохранён сетевой секрет для `api.cloudflare.com` («Cloudflare API token (Workers)»),
   прокси подставляет его вместо токена загрузки, и шаг 2 падает с 401 `token verification failure`
   (`X-Proxy-Error: upstream auth failed`). Шаги 1 и 3 этому не мешают — их делает коннектор Cloudflare.
4. Проверить живой сайт как браузер: `curl -H "Sec-Fetch-Mode: navigate" https://mip-site.imakanovr.workers.dev/` → 200.

Локально: `cd mip/public && python3 -m http.server 8080` → http://localhost:8080 (форма уйдёт в запасной режим — без воркера).

## Выкладка на hoster.kz (Plesk)

Хостинг «Эконом-1» для `mip.com.kz` оплачен (счёт 1600230 от 20.11.2026, на год). Домен уже смотрит на сервер
`185.116.195.196`; сайт на Cloudflare при этом остаётся запасным.

1. `python3 tools/pack-hoster.py` → `mip-hoster.zip`: всё из `public/` + `hoster/.htaccess` (заголовки безопасности,
   `/privacy` без `.html`, страница 404, кеш картинок) + `hoster/api/lead.php` (приём заявок вместо воркера).
   В архиве адрес заявок заменён на `/api/lead.php`; в самом `public/site.config.js` остаётся `/api/lead` (Cloudflare).
2. Plesk → «Сайты и домены» → mip.com.kz → «Файлы» → `httpdocs`: удалить заглушку, загрузить zip и распаковать
   (файлы должны лечь прямо в `httpdocs`, без вложенной папки; скрытые `.htaccess` должны остаться).
3. `httpdocs/api/config.sample.php` → переименовать в `config.php`, указать почту (и при желании Telegram). Пока его нет,
   заявки не принимаются, а посетитель видит кнопки «WhatsApp / почта / позвонить» с готовым текстом.
4. SSL: в карточке домена — Let's Encrypt (бесплатный сертификат, с `www`); потом в «Хостинг и DNS» включить
   перенаправление на HTTPS. Версию PHP — 7.2 или выше — в «Настройки PHP». В `.htaccess` HTTPS-перенаправления нет
   намеренно: до выпуска сертификата сайт перестал бы открываться.
5. Заявки пишутся в журнал `private/leads.log` рядом с `httpdocs` (если папки нет — `httpdocs/api/data/leads.log`;
   оба закрыты от интернета) и уходят письмом/в Telegram. Не больше 20 заявок за 10 минут с одного адреса.

Проверено на живом хостинге 23.11.2026: файлы совпадают с архивом, `/privacy`, 404, закрытые `api/config.php`, журнал и
папки работают. Но обычные файлы (главная, `app.js`, стили, `privacy.html`) Plesk отдаёт напрямую через nginx — заголовки
безопасности из `.htaccess` (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`) на них
не действуют, только на ответы Apache (`/privacy`, 404, PHP). Для сайта-визитки не критично. Если понадобится: Plesk →
«Apache и nginx» → «Дополнительные директивы nginx» и те же четыре строки через `add_header … always;`, а блок `Header`
из `.htaccess` убрать, иначе заголовки продублируются.

### Автоматическая выкладка (GitHub Actions → FTPS)

`.github/workflows/deploy-hoster.yml`: при каждом пуше в ветку `claude/peaceful-meitner-bw0e0i` (если менялись `public/`,
`hoster/`, `tools/pack-hoster.py` или сам файл) собирает тот же пакет и загружает его в `httpdocs` по FTPS, затем сверяет
`index.html`, `app.js`, `site.config.js`, `styles.css` на https://mip.com.kz с загруженными. Запустить вручную: Actions →
«Deploy to hoster.kz» → Run workflow. Ничего на сервере не удаляется (`api/config.php` и журнал заявок остаются).
Секреты репозитория: `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` — FTP-учётная запись `mipdeploy` из Plesk (домашняя папка
`/httpdocs`). Отключить выкладку: удалить файл workflow или FTP-учётную запись в Plesk. Если загрузка падает с ошибкой
сертификата — сервер FTP предъявляет сертификат не на тот адрес; пароль без шифрования не отправляется намеренно.

Обновления вручную: снова `pack-hoster.py` и загрузка поверх; `api/config.php` в архиве нет, поэтому не затирается.

## Фото

С Unsplash (бесплатная лицензия Unsplash, коммерческое использование разрешено, подпись не обязательна):

| Файл | Автор |
|---|---|
| `photo/hero.jpg` | Jayanth Muppaneni — https://unsplash.com/photos/MNkJpgjDfHo |
| `photo/valuation.jpg` | Cytonn Photography — https://unsplash.com/photos/GJao3ZTX9gU |
| `photo/replan.jpg` | Sven Mieke — https://unsplash.com/photos/fteR0e2BzKo |
| `photo/deals.jpg` | Jakub Żerdzicki — https://unsplash.com/photos/3HfGnyPfWqQ |
| `photo/movable.jpg` | Troy Mortier — https://unsplash.com/photos/eKY6_9W_iqY |
| `photo/consult.jpg` | Gabrielle Henderson — https://unsplash.com/photos/HJckKnwCXxQ |
| `photo/room.jpg` | Andrew Neel — https://unsplash.com/photos/DLD5LvnFblU |

Своё фото офиса или здания клиента лучше поставить вместо `hero.jpg` (2400 px по ширине и копия 1200 px в `hero-1200.jpg`).
