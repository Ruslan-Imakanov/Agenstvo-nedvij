// Редактор сайта прямо на странице — открывается по адресу /?cms=1 после входа в /admin/.
// Тексты правятся на месте (щёлкнуть и печатать), у пунктов списков — кнопки «добавить / выше / ниже / удалить»,
// у фото — «Заменить фото». Контакты, партнёры, меню, история правок и администраторы — в окнах из верхней
// панели. На сайт всё уходит кнопкой «Сохранить»; до этого посетители видят прежний вариант.
import { SITE as BASE } from '/site.config.js';

const BOOT = window.CMS_BOOT || {};
const API = BOOT.api || '/admin/api.php';
let csrf = BOOT.csrf || '';
let rev = BOOT.rev || 0;
let lastSaved = null;
let saving = false;
let guard = true;

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const escText = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const escAttr = s => escText(s).replace(/"/g, '&quot;');
const clone = v => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));
const isObj = v => v !== null && typeof v === 'object' && !Array.isArray(v);
const lines = s => String(s || '').split('\n').map(x => x.trim()).filter(Boolean);
let uid = 0;

function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of kids.flat(9)) if (c != null && c !== false) el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  return el;
}

// ---------- Контакты: те же правила слияния, что в app.js ----------
function merge(a, b) {
  if (b === undefined) return a;
  if (b === null || typeof b !== 'object' || Array.isArray(b) || a === null || typeof a !== 'object' || Array.isArray(a)) return b;
  const out = { ...a };
  for (const k of Object.keys(b)) if (k !== '__proto__' && k !== 'constructor' && k !== 'prototype') out[k] = merge(a[k], b[k]);
  return out;
}
const norm = v => (v === undefined || v === null || v === false || v === '' ? null : v);
function diff(base, val) {
  if (isObj(base) && isObj(val)) {
    const out = {};
    for (const k of Object.keys(val)) { const d = diff(base[k], val[k]); if (d !== undefined) out[k] = d; }
    return Object.keys(out).length ? out : undefined;
  }
  return JSON.stringify(norm(base)) === JSON.stringify(norm(val)) ? undefined : val;
}
let configOv = clone(window.SITE_OVERRIDES) || null;
let configDirty = false;
const effective = () => merge(BASE, configOv || undefined);

// ---------- Поля ----------
const modeOf = el => (el.hasAttribute('data-edit-rich') ? (el.getAttribute('data-edit-rich') === 'block' ? 'block' : 'inline') : 'plain');
const INLINE = { B: 'b', STRONG: 'b', I: 'em', EM: 'em', A: 'a', BR: 'br' };
const BLOCKS = { P: 'p', DIV: 'p', UL: 'ul', OL: 'ol', LI: 'li' };

// HTML поля только из разрешённых тегов (сервер всё равно чистит сам)
function htmlOf(root, block) {
  let out = '';
  (function walk(n) {
    for (const c of n.childNodes) {
      if (c.nodeType === Node.TEXT_NODE) { out += escText(c.data); continue; }
      if (c.nodeType !== Node.ELEMENT_NODE || c.classList.contains('cms-ui')) continue;
      const tag = INLINE[c.tagName] || (block ? BLOCKS[c.tagName] : null);
      if (!tag) { walk(c); if (!block && /^(P|DIV|LI|H[1-6])$/.test(c.tagName)) out += '<br>'; continue; }
      if (tag === 'br') { out += '<br>'; continue; }
      if (tag === 'a') {
        out += `<a href="${escAttr(c.getAttribute('href') || '')}"${c.hasAttribute('data-co-phone') ? ' data-co-phone' : ''}${c.hasAttribute('data-co-email') ? ' data-co-email' : ''}>`;
        walk(c);
        out += '</a>';
        continue;
      }
      out += `<${tag}>`;
      walk(c);
      out += `</${tag}>`;
    }
  })(root);
  return out.replace(/[ \t\r\n]+/g, ' ').replace(/^(?:\s|<br>)+|(?:\s|<br>)+$/g, '');
}
const serial = (el, mode = modeOf(el)) => (mode === 'plain' ? el.textContent.replace(/[ \t\r\n]+/g, ' ').trim() : htmlOf(el, mode === 'block'));

const PLAIN_CE = (() => {
  const d = document.createElement('div');
  try { d.contentEditable = 'plaintext-only'; } catch { return 'true'; }
  return d.contentEditable === 'plaintext-only' ? 'plaintext-only' : 'true';
})();
function setupField(el) {
  const mode = modeOf(el);
  el.contentEditable = mode === 'plain' ? PLAIN_CE : 'true';
  el.classList.add('cms-field');
  el.dataset.cmsMode = mode;
  el.spellcheck = true;
  el.dataset.cmsPh = 'Пусто — введите текст';
}

const fields = [];       // { el, key, mode, orig } — отдельные тексты
const lists = [];        // { box, key, orig } — списки
const imgs = new Map();  // ключ → { els, orig: {src, alt}, cur }
const dlgOrig = new Map();
const dlgText = new Map(); // заголовок, описание и пункты меню — правятся в окне «Меню и поиск»

const itemsOf = box => [...box.children].filter(c => c.hasAttribute('data-edit-item'));
const fieldsOf = it => [it, ...it.querySelectorAll('[data-edit-field]')].filter(x => x.hasAttribute('data-edit-field'));
function listValue(box) {
  return itemsOf(box).map(it => {
    const f = {};
    for (const el of fieldsOf(it)) f[el.dataset.editField] = serial(el);
    return { src: Number(it.dataset.editSrc ?? -1), f };
  });
}
function renumber(box) {
  itemsOf(box).forEach((it, i) => it.querySelectorAll('[data-edit-auto]').forEach(n => { n.textContent = String(i + 1); }));
}

// ---------- Что не сохранено ----------
function pending() {
  const text = {};
  for (const r of fields) { const v = serial(r.el, r.mode); if (v !== r.orig) text[r.key] = v; }
  for (const [k, v] of dlgText) if (v !== dlgOrig.get(k)) text[k] = v;
  const list = {};
  for (const l of lists) { const v = listValue(l.box); if (JSON.stringify(v) !== l.orig) list[l.key] = v; }
  const img = {};
  for (const [k, r] of imgs) if (r.cur && (r.cur.src !== r.orig.src || r.cur.alt !== r.orig.alt)) img[k] = r.cur;
  const out = { text, list, img, count: Object.keys(text).length + Object.keys(list).length + Object.keys(img).length + (configDirty ? 1 : 0) };
  if (configDirty) out.config = configOv;
  return out;
}
function commit() {
  for (const r of fields) r.orig = serial(r.el, r.mode);
  for (const [k, v] of dlgText) dlgOrig.set(k, v);
  dlgText.clear();
  for (const l of lists) l.orig = JSON.stringify(listValue(l.box));
  for (const r of imgs.values()) { if (r.cur) r.orig = { ...r.cur }; r.cur = null; }
  configDirty = false;
}

// ---------- Верхняя панель ----------
const plural = (n, a, b, c) => { const m10 = n % 10, m100 = n % 100; return m10 === 1 && m100 !== 11 ? a : m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20) ? b : c; };
const fmtDate = s => { if (!s) return ''; const d = new Date(s); return isNaN(d) ? String(s) : d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
const fmtTime = d => d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

const statusEl = h('span', { class: 'cms-status', 'aria-live': 'polite' });
const saveBtn = h('button', { type: 'button', class: 'cms-btn primary', onclick: () => save() }, 'Сохранить');
const undoBtn = h('button', { type: 'button', class: 'cms-btn cms-undo', onclick: revertAll }, 'Отменить');
const ACTIONS = [
  ['Контакты', 'Контакты и специалисты', () => openContacts()],
  ['Партнёры', 'Партнёры', () => openPartners()],
  ['Меню и поиск', 'Меню и поиск', () => openSettings()],
  ['История', 'История правок', () => openHistory()],
  ['Администраторы', 'Администраторы', () => openUsers()],
  ['Подсказка', 'Как пользоваться', () => openHelp()],
  ['Сайт', 'Открыть сайт как посетитель', () => window.open('/', '_blank', 'noopener')],
  ['Выйти', 'Выйти из кабинета', () => logout()],
];
const menu = h('div', { class: 'cms-ui cms-menu', hidden: true, role: 'menu' },
  ACTIONS.map(([, long, fn]) => h('button', { type: 'button', role: 'menuitem', onclick: () => { menu.hidden = true; fn(); } }, long)));
const menuBtn = h('button', { type: 'button', class: 'cms-btn cms-menu-btn', 'aria-haspopup': 'menu', onclick: e => { e.stopPropagation(); menu.hidden = !menu.hidden; } }, '☰ Меню');
const bar = h('div', { class: 'cms-ui cms-bar', role: 'toolbar', 'aria-label': 'Редактор сайта' },
  h('span', { class: 'cms-title' }, '✎ Редактор сайта'), statusEl, h('span', { class: 'cms-sp' }),
  h('span', { class: 'cms-wide' }, ACTIONS.slice(0, 4).map(([short, long, fn]) => h('button', { type: 'button', class: 'cms-btn', title: long, onclick: fn }, short))),
  menuBtn, undoBtn, saveBtn);
document.addEventListener('click', e => { if (!menu.hidden && !e.target.closest('.cms-menu')) menu.hidden = true; });

let statusRaf = 0;
function changed() {
  if (statusRaf) return;
  statusRaf = requestAnimationFrame(() => { statusRaf = 0; refreshStatus(); place(); });
}
function refreshStatus() {
  const n = pending().count;
  saveBtn.disabled = saving || n === 0;
  undoBtn.disabled = saving || n === 0;
  bar.classList.toggle('dirty', n > 0);
  statusEl.textContent = saving ? 'Сохраняю…'
    : n ? `Не сохранено: ${n} ${plural(n, 'изменение', 'изменения', 'изменений')}`
    : lastSaved ? `Всё сохранено · ${fmtTime(lastSaved)}` : 'Щёлкните по тексту, чтобы изменить';
}

// ---------- Сообщения и окна ----------
const toastEl = h('div', { class: 'cms-ui cms-toast', role: 'status' });
function toast(msg) {
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  clearTimeout(toast.t);
  toast.t = setTimeout(() => toastEl.classList.remove('show'), 3800);
}
const busyEl = h('div', { class: 'cms-ui cms-busy', hidden: true });
function busy(msg) { busyEl.hidden = !msg; busyEl.textContent = msg || ''; }

function dialog(title, body, actions, { wide = false, onClose } = {}) {
  const box = h('div', { class: 'cms-dlg-box' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
  const wrap = h('div', { class: 'cms-ui cms-dlg' }, h('div', { class: 'cms-dlg-shade', onclick: () => close() }), box);
  let closed = false;
  const close = () => {
    if (closed) return;
    closed = true;
    wrap.remove();
    document.removeEventListener('keydown', onKey, true);
    if (onClose) onClose();
  };
  const onKey = e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
  const foot = h('div', { class: 'cms-dlg-foot' },
    (actions || []).map(a => h('button', { type: 'button', class: 'cms-btn' + (a.primary ? ' primary' : '') + (a.danger ? ' danger' : ''), onclick: () => a.onClick(close) }, a.label)));
  box.append(
    h('div', { class: 'cms-dlg-head' }, h('h2', null, title), h('button', { type: 'button', class: 'cms-x', 'aria-label': 'Закрыть', onclick: () => close() }, '✕')),
    h('div', { class: 'cms-dlg-body' }, body), foot);
  document.addEventListener('keydown', onKey, true);
  document.body.append(wrap);
  setTimeout(() => { const f = box.querySelector('.cms-dlg-body input, .cms-dlg-body textarea') || box.querySelector('.cms-dlg-foot .primary'); if (f) f.focus(); }, 40);
  return { close, box };
}
const cancelAction = { label: 'Отмена', onClick: c => c() };
function ask(title, text, yes = 'Да', danger = false) {
  return new Promise(res => dialog(title, h('p', null, text), [
    { label: 'Отмена', onClick: c => { res(false); c(); } },
    { label: yes, primary: !danger, danger, onClick: c => { res(true); c(); } }], { onClose: () => res(false) }));
}
function alertDlg(title, text) {
  return new Promise(res => dialog(title, h('p', null, text || 'Что-то пошло не так. Попробуйте ещё раз.'),
    [{ label: 'Понятно', primary: true, onClick: c => c() }], { onClose: res }));
}
function row(label, value, { hint, type = 'text', area = false, ph, auto } = {}) {
  const id = 'cms-f' + (++uid);
  const inp = area ? h('textarea', { id, rows: 3, placeholder: ph }) : h('input', { id, type, placeholder: ph, autocomplete: auto || 'off' });
  inp.value = value ?? '';
  return { row: h('div', { class: 'cms-row' }, h('label', { class: 'cms-lbl', for: id }, label), inp, hint ? h('small', null, hint) : null), inp };
}

// ---------- Сервер ----------
async function api(action, body, opt = {}) {
  let res;
  let data = {};
  try {
    res = await fetch(`${API}?a=${encodeURIComponent(action)}`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CMS-CSRF': csrf, 'Content-Type': opt.type || 'application/json' },
      body: opt.raw || JSON.stringify(body || {})
    });
    data = await res.json().catch(() => ({}));
  } catch {
    return { ok: false, status: 0, message: 'Нет связи с сервером. Проверьте интернет и попробуйте ещё раз.' };
  }
  if ((res.status === 401 || (res.status === 403 && data.error === 'csrf')) && !opt.retried) {
    if (await relogin()) return api(action, body, { ...opt, retried: true });
  }
  return { ...data, status: res.status, ok: res.ok && data.ok === true };
}
async function freshToken() {
  try {
    const r = await fetch(`${API}?a=token`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' });
    const d = await r.json();
    return r.ok && d.ok ? d.csrf : '';
  } catch { return ''; }
}
function relogin() {
  return new Promise(res => {
    let done = false;
    const finish = (v, c) => { if (!done) { done = true; res(v); } c(); };
    dialog('Нужно войти снова', h('div', null,
      h('p', null, 'Время входа истекло (или вы вышли на другой вкладке). Правки на этой странице не потеряны.'),
      h('p', null, '1. Нажмите «Открыть вход» и войдите на новой вкладке. 2. Вернитесь сюда и нажмите «Продолжить».')), [
      { label: 'Открыть вход', onClick: () => window.open('/admin/', '_blank') },
      { label: 'Продолжить', primary: true, onClick: async c => { const t = await freshToken(); if (t) { csrf = t; finish(true, c); } else toast('Вход ещё не выполнен'); } }
    ], { onClose: () => { if (!done) { done = true; res(false); } } });
  });
}

async function save({ reload = false, force = false } = {}) {
  if (saving) return false;
  const p = pending();
  if (!p.count) {
    if (reload) { guard = false; location.reload(); return true; }
    toast('Изменений нет — сохранять нечего');
    return true;
  }
  saving = true;
  refreshStatus();
  const body = { rev, force, text: p.text, list: p.list, img: p.img };
  if ('config' in p) body.config = p.config;
  const r = await api('save', body);
  saving = false;
  if (r.status === 409) {
    refreshStatus();
    const who = r.by ? ` (${r.by}, ${fmtDate(r.at)})` : '';
    const yes = await ask('Сайт уже изменили', `Пока вы редактировали, сайт сохранил другой администратор${who}. Сохранить ваши изменения поверх? Его правки в других местах сохранятся.`, 'Сохранить поверх');
    return yes ? save({ reload: true, force: true }) : false;
  }
  if (!r.ok) {
    refreshStatus();
    await alertDlg('Не удалось сохранить', r.message);
    return false;
  }
  rev = r.rev;
  commit();
  lastSaved = new Date();
  refreshStatus();
  toast('Сохранено — изменения уже на сайте');
  if (reload || force) { guard = false; location.reload(); }
  return true;
}

async function revertAll() {
  if (pending().count && !(await ask('Отменить изменения?', 'Все несохранённые правки на этой странице пропадут, вернётся то, что сейчас на сайте.', 'Отменить изменения', true))) return;
  guard = false;
  location.reload();
}
async function logout() {
  if (pending().count && !(await ask('Выйти без сохранения?', 'Есть несохранённые изменения — они пропадут.', 'Выйти', true))) return;
  await api('logout', {});
  guard = false;
  location.href = '/';
}
addEventListener('beforeunload', e => { if (guard && pending().count) { e.preventDefault(); e.returnValue = ''; } });

// ---------- Фото ----------
function chooseFile() {
  return new Promise(res => {
    const inp = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/heic,image/heif', style: 'position:fixed;left:-9999px' });
    inp.addEventListener('change', () => { res(inp.files[0] || null); inp.remove(); }, { once: true });
    document.body.append(inp);
    inp.click();
  });
}
// Уменьшаем фото в браузере (длинная сторона — не больше max) и пересохраняем: так оно лёгкое,
// а сведения из файла (например, координаты съёмки) на сайт не попадают.
async function prepareImage(file, max) {
  const url = URL.createObjectURL(file);
  try {
    const img = new Image();
    img.src = url;
    await img.decode();
    const w = img.naturalWidth, hh = img.naturalHeight;
    if (!w || !hh) throw new Error('size');
    const s = Math.min(1, max / Math.max(w, hh));
    const c = document.createElement('canvas');
    c.width = Math.round(w * s);
    c.height = Math.round(hh * s);
    const ctx = c.getContext('2d');
    let type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
    if (type === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); }
    ctx.drawImage(img, 0, 0, c.width, c.height);
    let blob = await new Promise(r => c.toBlob(r, type, 0.86));
    if (blob && type === 'image/png' && blob.size > 3 * 1048576) {
      type = 'image/jpeg';
      blob = await new Promise(r => c.toBlob(r, type, 0.86));
    }
    if (!blob) throw new Error('encode');
    return { blob, type };
  } finally { URL.revokeObjectURL(url); }
}
async function uploadImage(file, max) {
  busy('Загружаю фото…');
  try {
    const p = await prepareImage(file, max);
    const r = await api('upload', null, { raw: p.blob, type: p.type });
    if (!r.ok) { busy(null); await alertDlg('Фото не загрузилось', r.message); return ''; }
    return r.src;
  } catch {
    busy(null);
    await alertDlg('Фото не загрузилось', 'Не удалось открыть файл. Сохраните фото в формате JPG и попробуйте ещё раз.');
    return '';
  } finally { busy(null); }
}
async function replaceImage(key) {
  const rec = imgs.get(key);
  const file = await chooseFile();
  if (!file) return;
  const src = await uploadImage(file, key === 'hero' ? 2400 : 1600);
  if (!src) return;
  let alt = (rec.cur || rec.orig).alt;
  if (alt) {
    const v = prompt('Коротко опишите, что на новом фото (для поисковиков и незрячих посетителей):', alt);
    if (v !== null && v.trim()) alt = v.trim();
  }
  rec.cur = { src, alt };
  for (const img of rec.els) {
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.loading = 'eager';
    img.src = src;
    img.alt = alt;
  }
  changed();
  toast('Фото заменено — не забудьте нажать «Сохранить»');
}
function photoRow(label, value, max) {
  let cur = value || '';
  const img = h('img', { alt: '', class: 'cms-thumb' });
  const info = h('span', { class: 'cms-muted' });
  const del = h('button', { type: 'button', class: 'cms-btn sm', onclick: () => { cur = ''; paint(); } }, 'Убрать');
  const pick = h('button', { type: 'button', class: 'cms-btn sm', onclick: async () => {
    const file = await chooseFile();
    if (!file) return;
    const src = await uploadImage(file, max);
    if (src) { cur = src.replace(/^img\//, ''); paint(); }
  } }, 'Загрузить');
  function paint() {
    img.hidden = !cur;
    if (cur) img.src = '/img/' + cur;
    info.textContent = cur ? '' : 'Нет';
    del.hidden = !cur;
  }
  paint();
  return { row: h('div', { class: 'cms-row' }, h('span', { class: 'cms-lbl' }, label), h('div', { class: 'cms-photo' }, img, info, pick, del)), get value() { return cur; } };
}

// ---------- Плавающие кнопки: у фото, у списков, над текстом с оформлением ----------
const layer = h('div', { class: 'cms-ui cms-layer' });
const chips = [];
function chip(target, label, onClick, where = 'tr') {
  const el = h('button', { type: 'button', class: 'cms-chip', onclick: onClick }, label);
  layer.append(el);
  chips.push({ target, el, where });
}
const shown = el => el.isConnected && el.getClientRects().length > 0;

let activeItem = null;
const ib = (label, title, fn, cls) => h('button', { type: 'button', title, 'aria-label': title, class: cls, onmousedown: e => e.preventDefault(), onclick: fn }, label);
const itemBar = h('div', { class: 'cms-ui cms-itembar', hidden: true },
  ib('＋', 'Добавить пункт ниже', () => { const it = activeItem; const rec = it && lists.find(l => l.box === it.parentElement); if (rec) addItem(rec, it); }),
  ib('↑', 'Переместить выше', () => moveItem(-1)),
  ib('↓', 'Переместить ниже', () => moveItem(1)),
  ib('✕', 'Удалить пункт', removeItem, 'del'));
function setActive(it) {
  if (activeItem === it) return;
  if (activeItem) activeItem.classList.remove('cms-item-active');
  activeItem = it || null;
  if (activeItem) activeItem.classList.add('cms-item-active');
  place();
}
function moveItem(d) {
  const it = activeItem;
  if (!it) return;
  const box = it.parentElement;
  const items = itemsOf(box);
  const j = items.indexOf(it) + d;
  if (j < 0 || j >= items.length) return;
  if (d < 0) items[j].before(it); else items[j].after(it);
  renumber(box);
  changed();
  it.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
async function removeItem() {
  const it = activeItem;
  if (!it) return;
  if (!(await ask('Удалить пункт?', 'Пункт исчезнет со страницы. До сохранения его можно вернуть кнопкой «Отменить».', 'Удалить', true))) return;
  const box = it.parentElement;
  setActive(null);
  it.remove();
  renumber(box);
  changed();
}
const ADD = { faq: 'Добавить вопрос', extra: 'Добавить свой блок', 'val.steps': 'Добавить шаг', 'replan.flow': 'Добавить этап', 'deals.flow': 'Добавить этап' };
const PH = { q: 'Новый вопрос', a: 'Ответ на вопрос', title: 'Заголовок', text: 'Описание', t: 'Новый пункт' };
function addItem(rec, after) {
  const box = rec.box;
  const items = itemsOf(box);
  const tpl = [...box.children].find(c => c.tagName === 'TEMPLATE' && c.hasAttribute('data-edit-template'));
  const ref = after || items[items.length - 1] || null;
  let el;
  let src;
  if (tpl && tpl.content.firstElementChild) {
    el = tpl.content.firstElementChild.cloneNode(true);
    src = -1;
  } else if (ref) {
    el = ref.cloneNode(true);
    src = Number(ref.dataset.editSrc ?? items.length - 1);
    for (const f of fieldsOf(el)) f.textContent = PH[f.dataset.editField] || 'Новый пункт';
  } else return;
  el.dataset.editSrc = String(src);
  el.classList.remove('cms-item-active');
  for (const f of fieldsOf(el)) setupField(f);
  if (el.tagName === 'DETAILS') el.open = true;
  if (ref) ref.after(el); else box.append(el);
  renumber(box);
  setActive(el);
  changed();
  const first = fieldsOf(el).find(f => f !== el) || fieldsOf(el)[0];
  if (first) {
    first.focus();
    const r = document.createRange();
    r.selectNodeContents(first);
    const s = getSelection();
    s.removeAllRanges();
    s.addRange(r);
  }
  el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

let richField = null;
const rb = (label, title, fn, cls) => h('button', { type: 'button', title, 'aria-label': title, class: cls, onmousedown: e => e.preventDefault(),
  onclick: () => { fn(); if (richField) changed(); } }, label);
const richBar = h('div', { class: 'cms-ui cms-richbar', hidden: true },
  rb('Ж', 'Жирный (Ctrl+B)', () => document.execCommand('bold'), 'b'),
  rb('К', 'Курсив (Ctrl+I)', () => document.execCommand('italic'), 'i'),
  rb('🔗', 'Ссылка', editLink),
  rb('•', 'Список', () => document.execCommand('insertUnorderedList'), 'blk'),
  rb('1.', 'Нумерованный список', () => document.execCommand('insertOrderedList'), 'blk'),
  rb('⌫', 'Убрать оформление выделенного', () => { document.execCommand('removeFormat'); document.execCommand('unlink'); }));
function showRich(f) { richField = f; richBar.hidden = false; richBar.classList.toggle('block', f.dataset.cmsMode === 'block'); place(); }
function hideRich() { richField = null; richBar.hidden = true; }
function normUrl(v) {
  v = String(v || '').trim();
  if (!v || v === 'https://') return '';
  if (/^(https?:\/\/\S+|mailto:\S+|tel:[\d+() -]+|#[\w.-]*|\/(?!\/)\S*)$/i.test(v)) return v;
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'mailto:' + v;
  if (/^\+?[\d\s()-]{7,}$/.test(v)) return 'tel:' + v.replace(/[^\d+]/g, '');
  if (/^[\w.-]+\.[a-z]{2,}(\/\S*)?$/i.test(v)) return 'https://' + v;
  return '';
}
function editLink() {
  const sel = getSelection();
  if (!richField || !sel.rangeCount) return;
  const range = sel.getRangeAt(0).cloneRange();
  const restore = () => { richField.focus(); sel.removeAllRanges(); sel.addRange(range); };
  const node = sel.anchorNode;
  const a = (node && (node.nodeType === 1 ? node : node.parentElement))?.closest('a');
  if (a && richField.contains(a)) {
    const v = prompt('Адрес ссылки (сотрите, чтобы убрать ссылку):', a.getAttribute('href') || '');
    restore();
    if (v === null) return;
    const url = normUrl(v);
    if (url) a.setAttribute('href', url);
    else { const p = a.parentNode; while (a.firstChild) p.insertBefore(a.firstChild, a); a.remove(); }
    changed();
    return;
  }
  if (sel.isCollapsed) { toast('Сначала выделите слова, которые станут ссылкой'); return; }
  const v = prompt('Адрес ссылки: сайт (https://…), почта или телефон', 'https://');
  restore();
  if (v === null) return;
  const url = normUrl(v);
  if (!url) { toast('Такой адрес не подойдёт: нужен сайт, почта или телефон'); return; }
  document.execCommand('createLink', false, url);
  changed();
}

let placeRaf = 0;
function place() { if (!placeRaf) placeRaf = requestAnimationFrame(() => { placeRaf = 0; doPlace(); }); }
function doPlace() {
  for (const c of chips) {
    const r = c.target.getBoundingClientRect();
    const vis = shown(c.target) && r.width > 40 && r.height > 24;
    c.el.hidden = !vis;
    if (!vis) continue;
    const x = Math.max(8, r.right + scrollX - c.el.offsetWidth - 10);
    // у фото первого экрана кнопка — сразу под шапкой сайта, в отступе над текстом
    const top = c.where === 'hero' ? r.top + (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-h')) || 76) + 18 : r.top + 10;
    const y = top + scrollY;
    c.el.style.transform = `translate(${Math.round(x)}px, ${Math.round(y)}px)`;
  }
  if (activeItem && shown(activeItem)) {
    itemBar.hidden = false;
    const r = activeItem.getBoundingClientRect();
    const x = Math.max(8, Math.min(r.right + scrollX - itemBar.offsetWidth + 4, scrollX + innerWidth - itemBar.offsetWidth - 8));
    itemBar.style.transform = `translate(${Math.round(x)}px, ${Math.round(r.top + scrollY - itemBar.offsetHeight - 6)}px)`;
  } else itemBar.hidden = true;
  if (richField && richField.isConnected) {
    // над правым краем поля: слева над полем обычно стоит другой текст (вопрос над ответом, заголовок над абзацем)
    const r = richField.getBoundingClientRect();
    const x = Math.max(8, Math.min(r.right + scrollX - richBar.offsetWidth, scrollX + innerWidth - richBar.offsetWidth - 8));
    richBar.style.transform = `translate(${Math.round(x)}px, ${Math.round(r.top + scrollY - richBar.offsetHeight - 10)}px)`;
  }
}

// ---------- Окна ----------
const DIR_NAMES = { valuation: 'Оценка', replan: 'Перепланировка', deals: 'Риелтор' };
function setConfig(next) {
  const ov = {};
  const dc = diff(BASE.company, next.company);
  if (dc) ov.company = dc;
  const ds = diff(BASE.specialists, next.specialists);
  if (ds) ov.specialists = ds;
  if (diff(BASE.partners, next.partners) !== undefined) ov.partners = next.partners;
  const val = Object.keys(ov).length ? ov : null;
  if (JSON.stringify(val) !== JSON.stringify(configOv || null)) { configOv = val; configDirty = true; changed(); }
}

function openContacts() {
  const eff = effective();
  const co = eff.company;
  const cPhone = row('Общий телефон', co.phone, { type: 'tel', hint: 'В шапке сайта, в меню и в контактах' });
  const cEmail = row('Общая почта', co.email, { type: 'email' });
  const cHours = row('Часы работы', co.hours, { hint: 'Например: Пн–Пт, 9:00–18:00. Пусто — на сайте не показываются' });
  const forms = {};
  const tabs = h('div', { class: 'cms-tabs', role: 'tablist' });
  const panes = h('div');
  for (const dir of Object.keys(DIR_NAMES)) {
    const s = eff.specialists[dir] || {};
    const F = {
      title: row('Название направления', s.title, { hint: 'В форме записи и в контактах' }),
      name: row('Имя специалиста или организация', s.name, { hint: 'Пусто — вместо имени показывается должность' }),
      role: row('Должность', s.role),
      about: row('Коротко о работе', s.about, { hint: 'Под должностью, пока имя не указано' }),
      phone: row('Телефон', s.phone, { type: 'tel', hint: 'Пусто — общий телефон компании' }),
      whatsapp: row('WhatsApp', s.whatsapp, { type: 'tel', hint: 'Номер, к которому привязан WhatsApp. Пусто — без кнопки WhatsApp' }),
      telegram: row('Telegram', s.telegram, { hint: 'Имя без @, например media_ocenka' }),
      instagram: row('Instagram', s.instagram, { hint: 'Имя без @' }),
      email: row('Почта', s.email, { type: 'email', hint: 'Пусто — общая почта компании' }),
      address: row('Адрес приёма', s.address)
    };
    const photo = photoRow('Фото специалиста', s.photo, 480);
    const icon = h('input', { type: 'checkbox' });
    icon.checked = !!s.avatarIcon;
    forms[dir] = { F, photo, icon };
    const pane = h('div', { class: 'cms-pane', hidden: dir !== 'valuation' }, F.title.row, F.name.row, F.role.row, F.about.row, photo.row,
      h('label', { class: 'cms-check' }, icon, h('span', null, 'Вместо фото и инициалов — значок направления (когда указана организация)')),
      F.phone.row, F.whatsapp.row, F.telegram.row, F.instagram.row, F.email.row, F.address.row);
    const tab = h('button', { type: 'button', role: 'tab', 'aria-selected': String(dir === 'valuation'), onclick: () => {
      $$('[role=tab]', tabs).forEach(t => t.setAttribute('aria-selected', String(t === tab)));
      [...panes.children].forEach(p => { p.hidden = p !== pane; });
    } }, DIR_NAMES[dir]);
    tabs.append(tab);
    panes.append(pane);
  }
  dialog('Контакты и специалисты', h('div', null,
    h('h3', null, 'Компания'), cPhone.row, cEmail.row, cHours.row,
    h('h3', null, 'Специалисты'), tabs, panes), [
    cancelAction,
    { label: 'Сохранить', primary: true, onClick: async close => {
      const next = clone(eff);
      next.company.phone = cPhone.inp.value.trim();
      next.company.email = cEmail.inp.value.trim();
      next.company.hours = cHours.inp.value.trim();
      if (!next.company.phone) { toast('Укажите общий телефон — он стоит в шапке сайта'); return; }
      for (const dir of Object.keys(forms)) {
        const { F, photo, icon } = forms[dir];
        const s = next.specialists[dir] = next.specialists[dir] || {};
        for (const k of Object.keys(F)) s[k] = F[k].inp.value.trim();
        s.photo = photo.value;
        s.avatarIcon = icon.checked;
      }
      setConfig(next);
      if (await save({ reload: true })) close();
    } }], { wide: true });
}

function openPartners() {
  const eff = effective();
  let list = clone(eff.partners || []);
  let forms = [];
  const wrap = h('div', { class: 'cms-partners' });
  const read = () => {
    list = forms.map(({ F, logo }) => ({
      name: F.name.inp.value.trim(), role: F.role.inp.value.trim(), logo: logo.value, lead: F.lead.inp.value.trim(),
      services: lines(F.services.inp.value), address: F.address.inp.value.trim(), phones: lines(F.phones.inp.value), email: F.email.inp.value.trim()
    }));
  };
  const render = () => {
    wrap.replaceChildren();
    forms = list.map((p, i) => {
      const F = {
        name: row('Название', p.name),
        role: row('Подпись над названием', p.role, { hint: 'Например: Архитектор · перепланировка' }),
        lead: row('Вступление', p.lead, { area: true }),
        services: row('Услуги — каждая с новой строки', (p.services || []).join('\n'), { area: true }),
        address: row('Адрес', p.address),
        phones: row('Телефоны — каждый с новой строки', (p.phones || []).join('\n'), { area: true }),
        email: row('Почта', p.email, { type: 'email' })
      };
      const logo = photoRow('Логотип', p.logo, 800);
      const tools = h('div', { class: 'cms-card-tools' },
        h('button', { type: 'button', class: 'cms-btn sm', onclick: () => { read(); if (i > 0) { [list[i - 1], list[i]] = [list[i], list[i - 1]]; render(); } } }, '↑ Выше'),
        h('button', { type: 'button', class: 'cms-btn sm', onclick: () => { read(); if (i < list.length - 1) { [list[i + 1], list[i]] = [list[i], list[i + 1]]; render(); } } }, '↓ Ниже'),
        h('button', { type: 'button', class: 'cms-btn sm danger', onclick: async () => {
          if (!(await ask('Убрать партнёра?', `«${p.name || 'Без названия'}» исчезнет из блока «Наши партнёры».`, 'Убрать', true))) return;
          read(); list.splice(i, 1); render();
        } }, 'Удалить'));
      wrap.append(h('fieldset', { class: 'cms-card' }, h('legend', null, p.name || 'Новый партнёр'), tools,
        F.name.row, F.role.row, logo.row, F.lead.row, F.services.row, F.address.row, F.phones.row, F.email.row));
      return { F, logo };
    });
    if (!list.length) wrap.append(h('p', { class: 'cms-muted' }, 'Партнёров нет — блок «Наши партнёры» и ссылки на него на сайте не показываются.'));
  };
  render();
  const add = h('button', { type: 'button', class: 'cms-btn', onclick: () => {
    read();
    list.push({ name: '', role: '', logo: '', lead: '', services: [], address: '', phones: [], email: '' });
    render();
    const last = wrap.lastElementChild;
    if (last) { last.scrollIntoView({ block: 'nearest' }); const i = last.querySelector('input'); if (i) i.focus(); }
  } }, '+ Добавить партнёра');
  dialog('Партнёры', h('div', null, wrap, add), [
    cancelAction,
    { label: 'Сохранить', primary: true, onClick: async close => {
      read();
      if (list.some(p => !p.name)) { toast('Укажите название у каждого партнёра'); return; }
      const next = clone(eff);
      next.partners = list;
      setConfig(next);
      if (await save({ reload: true })) close();
    } }], { wide: true });
}

const NAV = [['dirs', 'Направления'], ['about', 'О компании'], ['valuation', 'Оценка'], ['replan', 'Перепланировка'], ['deals', 'Риелтор'],
  ['faq', 'Вопросы и ответы'], ['partners', 'Партнёры'], ['contacts', 'Контакты']];
const dlgVal = k => (dlgText.has(k) ? dlgText.get(k) : dlgOrig.get(k) || '');
function openSettings() {
  const title = row('Заголовок страницы', dlgVal('seo.title'), { hint: 'Во вкладке браузера и в результатах поиска — до 70 символов' });
  const desc = row('Описание для поиска', dlgVal('seo.description'), { area: true, hint: 'Под заголовком в Google и Яндексе — до 160 символов' });
  const navRows = NAV.map(([k, label]) => [k, row(`Пункт «${label}»`, dlgVal('nav.' + k))]);
  dialog('Меню и поиск', h('div', null,
    h('h3', null, 'Как сайт выглядит в поиске'), title.row, desc.row,
    h('h3', null, 'Пункты меню'), h('p', { class: 'cms-muted' }, 'Названия разделов в шапке, мобильном меню и подвале.'),
    navRows.map(x => x[1].row)), [
    cancelAction,
    { label: 'Сохранить', primary: true, onClick: async close => {
      const clean = v => v.replace(/\s+/g, ' ').trim();
      if (!clean(title.inp.value)) { toast('Заголовок страницы не может быть пустым'); return; }
      dlgText.set('seo.title', clean(title.inp.value));
      dlgText.set('seo.description', clean(desc.inp.value));
      document.title = clean(title.inp.value);
      for (const [k, f] of navRows) {
        const v = clean(f.inp.value) || dlgOrig.get('nav.' + k) || '';
        dlgText.set('nav.' + k, v);
        $$(`[data-edit="nav.${k}"]`).forEach(el => { el.textContent = v; });
      }
      changed();
      if (await save()) close();
    } }], { wide: true });
}

async function openHistory() {
  const r = await api('history', {});
  if (!r.ok) { alertDlg('История недоступна', r.message); return; }
  const restoreTo = async target => {
    const lost = pending().count ? ' Несохранённые изменения на этой странице пропадут.' : '';
    const ok = target === 0
      ? await ask('Вернуть исходный сайт?', 'Все правки из кабинета уберутся — сайт станет таким, каким его сделал разработчик. Это тоже попадёт в историю, так что можно будет передумать.' + lost, 'Вернуть исходный', true)
      : await ask(`Вернуть версию №${target}?`, 'Сайт станет таким, каким был после этого сохранения. Текущий вариант останется в истории.' + lost, 'Вернуть');
    if (!ok) return;
    const x = await api('restore', { rev: target });
    if (!x.ok) { alertDlg('Не получилось', x.message); return; }
    guard = false;
    location.reload();
  };
  const rows = r.items.map(it => h('div', { class: 'cms-hrow' + (it.rev === r.rev ? ' cur' : '') },
    h('div', null, h('b', null, `№${it.rev}`), ` · ${fmtDate(it.at)}${it.by ? ' · ' + it.by : ''}`, h('div', { class: 'cms-muted' }, it.note)),
    it.rev === r.rev ? h('span', { class: 'cms-badge' }, 'сейчас на сайте')
      : h('button', { type: 'button', class: 'cms-btn sm', onclick: () => restoreTo(it.rev) }, 'Вернуть')));
  dialog('История правок', h('div', null,
    h('p', null, 'Каждое сохранение — отдельная версия. Можно вернуть любую из последних 60; возврат тоже станет новой версией.'),
    rows.length ? rows : h('p', { class: 'cms-muted' }, 'Сохранений пока не было — сайт в исходном виде.')), [
    { label: 'Скачать копию правок', onClick: () => {
      const a = h('a', { href: `${API}?a=export&csrf=${encodeURIComponent(csrf)}`, download: '', hidden: true });
      document.body.append(a);
      a.click();
      a.remove();
    } },
    { label: 'Вернуть исходный сайт', danger: true, onClick: () => restoreTo(0) },
    { label: 'Закрыть', primary: true, onClick: c => c() }], { wide: true });
}

async function openUsers() {
  const r = await api('users', {});
  if (!r.ok) { alertDlg('Список недоступен', r.message); return; }
  const listEl = h('div');
  const paint = users => listEl.replaceChildren(...users.map(u => h('div', { class: 'cms-hrow' },
    h('div', null, h('b', null, u.login), u.me ? ' — это вы' : '',
      h('div', { class: 'cms-muted' }, `добавлен ${fmtDate(u.created)}${u.last ? ' · последний вход ' + fmtDate(u.last) : ''}`)),
    u.me ? null : h('button', { type: 'button', class: 'cms-btn sm danger', onclick: async () => {
      if (!(await ask('Удалить администратора?', `«${u.login}» больше не сможет входить в кабинет.`, 'Удалить', true))) return;
      const x = await api('user_del', { login: u.login });
      if (x.ok) paint(x.users); else alertDlg('Не получилось', x.message);
    } }, 'Удалить'))));
  paint(r.users);
  const nl = row('Логин', '', { hint: 'Латинские буквы, цифры, точка, дефис, подчёркивание', auto: 'off' });
  const np = row('Пароль', '', { type: 'password', hint: 'Не короче 10 символов. Передайте его человеку лично, не в открытом чате', auto: 'new-password' });
  const op = row('Текущий пароль', '', { type: 'password', auto: 'current-password' });
  const p1 = row('Новый пароль', '', { type: 'password', hint: 'Не короче 10 символов', auto: 'new-password' });
  const p2 = row('Новый пароль ещё раз', '', { type: 'password', auto: 'new-password' });
  dialog('Администраторы', h('div', null,
    h('h3', null, 'Кто может править сайт'), listEl,
    h('h3', null, 'Добавить администратора'), nl.row, np.row,
    h('button', { type: 'button', class: 'cms-btn', onclick: async () => {
      const x = await api('user_add', { login: nl.inp.value, password: np.inp.value });
      if (x.ok) { paint(x.users); nl.inp.value = np.inp.value = ''; toast('Администратор добавлен'); } else alertDlg('Не получилось', x.message);
    } }, 'Добавить'),
    h('h3', null, 'Сменить мой пароль'), op.row, p1.row, p2.row,
    h('button', { type: 'button', class: 'cms-btn', onclick: async () => {
      if (p1.inp.value !== p2.inp.value) { alertDlg('Пароли не совпадают', 'Введите новый пароль одинаково в обоих полях.'); return; }
      const x = await api('password', { old: op.inp.value, password: p1.inp.value });
      if (x.ok) { csrf = x.csrf; op.inp.value = p1.inp.value = p2.inp.value = ''; toast('Пароль изменён. На других устройствах нужно будет войти заново'); }
      else alertDlg('Пароль не изменён', x.message);
    } }, 'Сменить пароль'),
    h('h3', null, 'Безопасность'),
    h('p', { class: 'cms-muted' }, 'Если вошли на чужом компьютере или потеряли телефон — выйдите на всех устройствах сразу.'),
    h('button', { type: 'button', class: 'cms-btn danger', onclick: async () => {
      if (!(await ask('Выйти на всех устройствах?', 'Везде, где вы входили в кабинет, понадобится войти заново (и здесь тоже).', 'Выйти везде', true))) return;
      await api('logout_all', {});
      guard = false;
      location.href = '/admin/';
    } }, 'Выйти на всех устройствах')), [{ label: 'Закрыть', primary: true, onClick: c => c() }], { wide: true });
}

function openHelp() {
  const li = t => h('li', null, t);
  dialog('Как пользоваться редактором', h('div', null, h('ul', { class: 'cms-help' },
    li('Щёлкните по любому тексту на странице и печатайте — как в Word.'),
    li('Жирный, курсив, ссылка — кнопки над текстом (там, где оформление разрешено).'),
    li('У пунктов списков (вопросы, шаги, галочки) — кнопки ＋ ↑ ↓ ✕: добавить, переставить, удалить. Под каждым списком — «Добавить».'),
    li('Фото меняется кнопкой «Заменить фото» на самом фото.'),
    li('Телефоны, почта, специалисты и партнёры — кнопки «Контакты» и «Партнёры» в верхней панели.'),
    li('Свой блок с заголовком и текстом — «Добавить свой блок» перед контактами.'),
    li('Посетители ничего не увидят, пока вы не нажмёте «Сохранить». Ошиблись после сохранения — «История» → «Вернуть».'))),
  [{ label: 'Понятно', primary: true, onClick: c => c() }]);
}

// ---------- События на странице ----------
document.addEventListener('input', e => { if (e.target.closest && e.target.closest('.cms-field')) changed(); }, true);
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.code === 'KeyS') { e.preventDefault(); save(); return; }
  const f = e.target.closest && e.target.closest('.cms-field');
  if (!f) return;
  const mode = f.dataset.cmsMode;
  if (e.key === 'Enter' && mode !== 'block') {
    e.preventDefault();
    if (mode === 'inline' && !document.execCommand('insertLineBreak')) document.execCommand('insertHTML', false, '<br>');
    changed();
    return;
  }
  if (mode === 'plain' && (e.ctrlKey || e.metaKey) && ['KeyB', 'KeyI', 'KeyU'].includes(e.code)) e.preventDefault();
  if (e.key === ' ' && f.closest('summary')) { e.preventDefault(); document.execCommand('insertText', false, ' '); }
  if (e.key === 'Escape') f.blur();
}, true);
document.addEventListener('paste', e => {
  const f = e.target.closest && e.target.closest('.cms-field');
  if (!f) return;
  e.preventDefault();
  const t = ((e.clipboardData && e.clipboardData.getData('text/plain')) || '').replace(/\r/g, '');
  const mode = f.dataset.cmsMode;
  if (mode === 'plain') document.execCommand('insertText', false, t.replace(/\s*\n\s*/g, ' '));
  else if (mode === 'inline') document.execCommand('insertHTML', false, t.split('\n').map(escText).join('<br>'));
  else document.execCommand('insertHTML', false, t.split(/\n\s*\n/).map(p => '<p>' + p.split('\n').map(escText).join('<br>') + '</p>').join(''));
  changed();
}, true);
document.addEventListener('drop', e => { if (e.target.closest && e.target.closest('.cms-field')) e.preventDefault(); }, true);
document.addEventListener('click', e => {
  if (e.target.closest('.cms-ui')) return;
  const a = e.target.closest('a[href]');
  if (a && (a.closest('.cms-field') || a.querySelector('.cms-field'))) e.preventDefault();
  if (e.target.closest('summary')) e.preventDefault();
}, true);
document.addEventListener('pointerover', e => {
  if (e.target.closest('.cms-ui')) return;
  const it = e.target.closest('[data-edit-item]');
  if (it && lists.some(l => l.box === it.parentElement)) setActive(it);
});
document.addEventListener('focusin', e => {
  const f = e.target.closest && e.target.closest('.cms-field');
  if (!f) return;
  const it = f.closest('[data-edit-item]');
  if (it) setActive(it);
  if (f.dataset.cmsMode !== 'plain') showRich(f); else hideRich();
});
document.addEventListener('focusout', e => {
  const f = e.target.closest && e.target.closest('.cms-field');
  if (f && f === richField) setTimeout(() => { if (richField === f && document.activeElement !== f) hideRich(); }, 200);
});
addEventListener('scroll', place, { passive: true });
addEventListener('resize', place);
document.addEventListener('load', place, true);

// ---------- Старт ----------
function start() {
  document.documentElement.classList.add('cms-on');
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch { /* старый браузер */ }
  $$('details').forEach(d => { d.open = true; });

  for (const el of $$('[data-edit]')) {
    const key = el.getAttribute('data-edit');
    if (el.tagName === 'TITLE' || key.startsWith('seo.') || key.startsWith('nav.') || el.closest('[data-edit-item], template')) continue;
    setupField(el);
    fields.push({ el, key, mode: modeOf(el), orig: '' });
  }
  for (const box of $$('[data-edit-list]')) {
    if (box.closest('template')) continue;
    const rec = { box, key: box.getAttribute('data-edit-list'), orig: '' };
    lists.push(rec);
    itemsOf(box).forEach(it => fieldsOf(it).forEach(setupField));
    box.after(h('button', { type: 'button', class: 'cms-ui cms-add', onclick: () => addItem(rec) }, '+ ' + (ADD[rec.key] || 'Добавить пункт')));
  }
  for (const r of fields) r.orig = serial(r.el, r.mode);
  for (const l of lists) l.orig = JSON.stringify(listValue(l.box));

  for (const img of $$('img[data-edit-img]')) {
    const key = img.getAttribute('data-edit-img');
    if (!imgs.has(key)) {
      imgs.set(key, { els: [], orig: { src: img.getAttribute('src') || '', alt: img.getAttribute('alt') || '' }, cur: null });
      chip(img, '📷 Заменить фото', () => replaceImage(key), key === 'hero' ? 'hero' : 'tr');
    }
    imgs.get(key).els.push(img);
  }
  $$('[data-spec]').forEach(el => chip(el, '✎ Контакты специалиста', () => openContacts()));
  const office = $('.office.main');
  if (office) chip(office, '✎ Контакты', () => openContacts());
  const partners = $('[data-partners]');
  if (partners) chip(partners, '✎ Партнёры', () => openPartners());

  dlgOrig.set('seo.title', document.title.trim());
  dlgOrig.set('seo.description', ($('meta[name="description"]') || {}).content || '');
  for (const [k] of NAV) { const el = $(`[data-edit="nav.${k}"]`); dlgOrig.set('nav.' + k, el ? el.textContent.trim() : ''); }

  document.body.append(bar, menu, layer, itemBar, richBar, toastEl, busyEl);
  if (BOOT.at && BOOT.rev) statusEl.title = `Последнее сохранение: ${fmtDate(BOOT.at)}${BOOT.by ? ', ' + BOOT.by : ''}`;
  refreshStatus();
  place();
  if (document.fonts) document.fonts.ready.then(place);
  if ('ResizeObserver' in window) new ResizeObserver(place).observe(document.body);
  try {
    if (!localStorage.getItem('mip-cms-help')) { localStorage.setItem('mip-cms-help', '1'); openHelp(); }
  } catch { /* без localStorage подсказка просто не покажется сама */ }
}
start();
