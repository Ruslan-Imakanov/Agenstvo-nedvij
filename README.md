# MEDIA Информ Плюс — сайт-визитка

Одностраничный сайт ТОО «MEDIA Информ Плюс» (Костанай, Рудный): три направления — **оценка**, **перепланировка**,
**купля-продажа**, у каждого свой специалист и свои контакты. Кнопка «Связаться» открывает окно записи на консультацию
(как на data-pro.kz). Тексты об оценке — дословно из брифа клиента (`content/brief.md`).

Сайт: **https://mip-site.imakanovr.workers.dev** (Cloudflare Worker `mip-site`). Домен клиента — `mip.com.kz`
(куплен на hoster.kz). Подключение: добавить домен в Cloudflare (Domains → Add a domain, тариф Free), в панели
hoster.kz заменить NS-серверы на выданные Cloudflare (перед этим проверить, что Cloudflare подтянул MX/TXT почты),
затем Workers → mip-site → Settings → Domains & Routes → Add → Custom domain.

## Что где

| Файл | Что внутри |
|---|---|
| `public/index.html` | Вся страница: первый экран, направления, о компании, оценка (что оцениваем, 12 целей, заказчик, документы, 7 шагов), перепланировка, купля-продажа, вопросы, запись, партнёры, контакты |
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
принимает специалист (сейчас заполнено у оценки: Рудный, ул. Ленина, 135).

Общий номер компании (`company.phone`) стоит в шапке на любой ширине экрана; на телефонах он занимает место кнопки
«Связаться» — запись остаётся в первом экране, в нижней плавающей панели и в меню.

## Партнёры

Блок «Наши партнёры» (перед контактами) собирается из списка `partners` в `public/site.config.js`: название, подпись,
логотип, услуги, адрес, телефоны, почта. Новый партнёр — ещё один объект в списке; логотип — файл в
`public/img/partners/` (до 640 px по ширине). Пустой список — блок и ссылки на него в меню и подвале скрываются.

Сейчас там ТОО «Успех Проект» (архитектор, перепланировка). Логотип `img/partners/uspekh-proekt.jpg` — временный,
прислан клиентом: на картинке видны водяные знаки фотостока; клиент пришлёт новый.

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
4. Проверить живой сайт как браузер: `curl -H "Sec-Fetch-Mode: navigate" https://mip-site.imakanovr.workers.dev/` → 200.

Локально: `cd mip/public && python3 -m http.server 8080` → http://localhost:8080 (форма уйдёт в запасной режим — без воркера).

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
